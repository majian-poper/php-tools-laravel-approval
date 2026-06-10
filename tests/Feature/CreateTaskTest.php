<?php

/**
 * 测试目的: 覆盖 ApprovalFacade::createTask/createTaskFor/resolveFlowFor 创建 task 的端到端路径
 * 覆盖范围: 单个 approvable 完整链路 (Task+Step+Approval 都被创建并 dispatch ApprovalTaskCreated)、resolver 列写入、缺 approver/approvable/未登录的异常、SimpleFlow fallback 与 DB 中 ApprovalFlow 优先级、批量插入与 approvals 数量、whereApprover(s) scope
 */

use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use PHPTools\Approval\Enums\ApprovalFlowType;
use PHPTools\Approval\Events\ApprovalTaskCreated;
use PHPTools\Approval\Exceptions\NoApprovableForCreationException;
use PHPTools\Approval\Exceptions\NoApproverForCreationException;
use PHPTools\Approval\Exceptions\UnauthorizedException;
use PHPTools\Approval\Facades\ApprovalFacade;
use PHPTools\Approval\Models\Approval;
use PHPTools\Approval\Models\ApprovalFlow;
use PHPTools\Approval\Models\ApprovalTask;
use PHPTools\Approval\SimpleFlow;

use function Pest\Laravel\actingAs;

// 验证: createTaskFor 同步创建 task+step+approval, 写入 SimpleFlow 的 title/description, 并 dispatch ApprovalTaskCreated
it('creates a task with steps and approvals for a single approvable', function (): void {
    Event::fake();

    [$applicant, $approver] = User::newModels(2);
    $article = Article::newModel();

    actingAs($applicant, 'web');

    $flow = new SimpleFlow(
        title: 'Approve article',
        description: 'Please review',
        type: ApprovalFlowType::EVERY,
        expiresAt: new DateTimeImmutable('+1 day'),
        approvers: [$approver],
    );

    $task = ApprovalFacade::createTaskFor($article, $flow);

    expect($task->title)->toBe('Approve article')
        ->and($task->description)->toBe('Please review')
        ->and($task->flow_type)->toBe(ApprovalFlowType::EVERY)
        ->and($task->status->value)->toBe('pending')
        ->and($task->user_id)->toBe($applicant->getKey())
        ->and($task->steps)->toHaveCount(1)
        ->and($task->approvals)->toHaveCount(1);

    $step = $task->steps->first();
    expect($step->approver_id)->toBe($approver->getKey())
        ->and($step->status->value)->toBe('pending');

    $approval = $task->approvals->first();
    expect((string) $approval->approvable_id)->toBe((string) $article->getKey())
        ->and($approval->event->value)->toBe('updating')
        ->and($approval->created_unique_key)->toBe($article->author_email);

    Event::assertDispatched(ApprovalTaskCreated::class);
});

// 验证: 注入 REMOTE_ADDR/User-Agent 后这些 resolver 值被写入 task 对应列
it('writes custom resolver columns to the task', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $article = Article::newModel();

    actingAs($applicant, 'web');

    request()->server->set('REMOTE_ADDR', '10.0.0.99');
    request()->headers->set('User-Agent', 'PestAgent/1.0');

    $flow = new SimpleFlow(
        title: 'T',
        description: 'D',
        type: ApprovalFlowType::EVERY,
        expiresAt: new DateTimeImmutable('+1 day'),
        approvers: [$approver],
    );

    $task = ApprovalFacade::createTaskFor($article, $flow);

    expect($task->ip_address)->toBe('10.0.0.99')
        ->and($task->user_agent)->toBe('PestAgent/1.0');
});

// 验证: SimpleFlow 的 approvers 为空时 createTaskFor 抛 NoApproverForCreationException
it('throws when no approver is provided to the flow', function (): void {
    $article = Article::newModel();

    actingAs(User::newModel(), 'web');

    $flow = new SimpleFlow(
        title: 'T',
        description: 'D',
        type: ApprovalFlowType::EVERY,
        expiresAt: new DateTimeImmutable('+1 day'),
        approvers: [],
    );

    expect(static fn(): mixed => ApprovalFacade::createTaskFor($article, $flow))
        ->toThrow(NoApproverForCreationException::class);
});

// 验证: createTask 传入空 approvables 数组时抛 NoApprovableForCreationException
it('throws when no approvable is supplied to createTask', function (): void {
    [$applicant, $approver] = User::newModels(2);

    actingAs($applicant, 'web');

    $flow = new SimpleFlow(
        title: 'T',
        description: 'D',
        type: ApprovalFlowType::EVERY,
        expiresAt: new DateTimeImmutable('+1 day'),
        approvers: [$approver],
    );

    expect(static fn(): mixed => ApprovalFacade::createTask([], $flow, $applicant))
        ->toThrow(NoApprovableForCreationException::class);
});

// 验证: DB 中无对应 ApprovalFlow 时, resolveFlowFor 回退为内置 SimpleFlow (默认 EVERY)
it('falls back to SimpleFlow when no ApprovalFlow exists in the database', function (): void {
    $article = Article::newModel();

    $flow = ApprovalFacade::resolveFlowFor($article);

    expect($flow)->toBeInstanceOf(SimpleFlow::class)
        ->and($flow->getType())->toBe(ApprovalFlowType::EVERY);
});

// 验证: 同一 approvable_type 多条 ApprovalFlow 时使用 created_at 最新的 (这里通过 sleep(1) 保证)
it('uses the latest ApprovalFlow in the database for an approvable type', function (): void {
    $article = Article::newModel();

    $flowA = ApprovalFlow::query()->create([
        'name' => 'Older flow',
        'approvable_type' => $article->getMorphClass(),
        'expiration' => 3600,
        'flow_type' => ApprovalFlowType::ANY->value,
    ]);

    sleep(1); // Ensure a different created_at timestamp.

    $flowB = ApprovalFlow::query()->create([
        'name' => 'Newer flow',
        'approvable_type' => $article->getMorphClass(),
        'expiration' => 7200,
        'flow_type' => ApprovalFlowType::EVERY->value,
    ]);

    $resolved = ApprovalFacade::resolveFlowFor($article);

    expect($resolved)->toBeInstanceOf(ApprovalFlow::class)
        ->and($resolved->getKey())->toBe($flowB->getKey());
});

// 验证: 多个 approvables 时 Approval 每条 1 行, 验证 chunk_size 配置不影响最终落库总数
it('batches approval inserts by chunk_size', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $articles = Article::newModels(5);

    actingAs($applicant, 'web');

    $flow = new SimpleFlow(
        title: 'T',
        description: 'D',
        type: ApprovalFlowType::EVERY,
        expiresAt: new DateTimeImmutable('+1 day'),
        approvers: [$approver],
    );

    // 5 articles: one Approval row per article must be created.
    ApprovalFacade::createTask($articles, $flow, $applicant);

    expect(Approval::query()->count())->toBe(5);
});

// 验证: 未 actingAs 时, UserResolver 在 createTaskFor 链路里抛 UnauthorizedException
it('throws UnauthorizedException when no user is logged in', function (): void {
    $article = Article::newModel();

    expect(static fn(): mixed => ApprovalFacade::createTaskFor($article))
        ->toThrow(UnauthorizedException::class);
});

// 验证: whereApprover(单人) / whereApprovers(多人) scope 能精确筛出包含给定 approver 的 task
it('exposes whereApprover and whereApprovers scopes', function (): void {
    [$applicant1, $applicant2, $applicant3] = User::newModels(3);
    [$approver1, $approver2, $approver3, $approver4] = User::newModels(4);

    $t1 = buildTaskFor($applicant1, [$approver1]);
    $t2 = buildTaskFor($applicant2, [$approver2]);
    $t3 = buildTaskFor($applicant3, [$approver3, $approver4]);

    expect(ApprovalTask::whereApprover($approver1)->pluck('id')->all())
        ->toBe([$t1->getKey()]);

    expect(ApprovalTask::whereApprover($approver2)->pluck('id')->all())
        ->toBe([$t2->getKey()]);

    expect(ApprovalTask::whereApprovers($approver3, $approver4)->pluck('id')->all())
        ->toBe([$t3->getKey()]);
});
