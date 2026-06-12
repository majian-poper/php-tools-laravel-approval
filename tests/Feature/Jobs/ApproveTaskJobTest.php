<?php

/**
 * 测试目的: 覆盖 src/Jobs/ApproveTaskJob 的分块应用、状态守卫、事件触发与递归保护
 * 覆盖范围: 非 approving 时早退、UPDATING approval 应用并 dispatch ApprovalsAffecting/Affected/JobCompleted、chunkSize 分页时再 dispatch 下一页 job、最后一页完成后状态置 APPROVED、affect 在 forceRun 内执行避免触发新审批、displayName 拼装
 */

use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPTools\Approval\Enums\ApprovalFlowType;
use PHPTools\Approval\Enums\ApprovalStatus;
use PHPTools\Approval\Events\ApprovalsAffected;
use PHPTools\Approval\Events\ApprovalsAffecting;
use PHPTools\Approval\Events\ApproveTaskJobCompleted;
use PHPTools\Approval\Facades\ApprovalFacade;
use PHPTools\Approval\Jobs\ApproveTaskJob;
use PHPTools\Approval\Models\Approval;
use PHPTools\Approval\Models\ApprovalFlow;
use PHPTools\Approval\Models\ApprovalFlowStep;
use PHPTools\Approval\Models\ApprovalTask;
use PHPTools\Approval\SimpleFlow;

use function Pest\Laravel\actingAs;

function registerArticleFlowFor(User $approver): ApprovalFlow
{
    $flow = ApprovalFlow::query()->create([
        'name' => 'auto-article',
        'approvable_type' => (new Article)->getMorphClass(),
        'expiration' => 3600,
        'flow_type' => ApprovalFlowType::EVERY->value,
    ]);

    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->getKey(),
        'order_number' => 1,
        'approver_id' => $approver->getKey(),
        'approver_type' => $approver->getMorphClass(),
    ]);

    return $flow;
}

// 验证: task 还是 PENDING 时 job 早退, 不 dispatch 任何 ApprovalsAffecting/Completed 事件, 状态保持 PENDING
it('returns early when task is not in approving state', function (): void {
    Event::fake();

    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);
    // task is PENDING, not approving

    (new ApproveTaskJob($task, 100))->handle();

    Event::assertNotDispatched(ApprovalsAffecting::class);
    Event::assertNotDispatched(ApproveTaskJobCompleted::class);

    expect($task->fresh()->status)->toBe(ApprovalStatus::PENDING);
});

// 验证: 完整 UPDATING 流程: 审批通过→job 应用变更→title 真的更新, 期间 dispatch 三个事件、approval 标记 effected
it('applies UPDATING approvals and fires lifecycle events', function (): void {
    Queue::fake();

    [$applicant, $approver] = User::newModels(2);
    actingAs($applicant);

    registerArticleFlowFor($approver);

    $article = Article::newModel();
    $oldTitle = $article->title;

    // Trigger an UPDATING approval through the ShouldBeApproved trait
    $article->title = 'Updated title';
    $article->save();

    $task = ApprovalTask::query()->latest('id')->first();
    $task->approve('ok', $approver);
    $task = $task->fresh();

    expect($task->status)->toBe(ApprovalStatus::APPROVING);

    Event::fake();

    (new ApproveTaskJob($task, 100))->handle();

    Event::assertDispatched(ApprovalsAffecting::class);
    Event::assertDispatched(ApprovalsAffected::class);
    Event::assertDispatched(ApproveTaskJobCompleted::class);

    expect($task->fresh()->status)->toBe(ApprovalStatus::APPROVED)
        ->and($article->fresh()->title)->toBe('Updated title')
        ->and($article->fresh()->title)->not->toBe($oldTitle);

    /** @var Approval $approval */
    $approval = $task->fresh()->approvals->first();
    expect($approval->isEffected())->toBeTrue();
});

// 验证: 3 个 approval + chunkSize=2 时, 第一批处理 2 条并 re-dispatch page=2, task 仍 APPROVING
it('chunks approvals and re-dispatches itself when more remain', function (): void {
    Queue::fake();

    [$applicant, $approver] = User::newModels(2);
    $articles = Article::newModels(3);

    $flow = new SimpleFlow(
        title: 'T',
        description: 'D',
        type: ApprovalFlowType::EVERY,
        expiresAt: new DateTimeImmutable('+1 day'),
        approvers: [$approver],
    );

    $task = ApprovalFacade::createTask($articles, $flow, $applicant);
    $task->approve('ok', $approver);
    $task = $task->fresh();

    expect($task->status)->toBe(ApprovalStatus::APPROVING)
        ->and($task->approvals)->toHaveCount(3);

    // chunkSize 2 → first batch handles 2, then re-dispatches page 2
    (new ApproveTaskJob($task, 2))->handle();

    Queue::assertPushed(ApproveTaskJob::class, function ($job): bool {
        return $job->page === 2;
    });

    expect($task->fresh()->status)->toBe(ApprovalStatus::APPROVING);
});

// 验证: 当无更多 approval 时, dispatch JobCompleted 并把 task 置为 APPROVED (不再 re-dispatch 自身)
it('marks task as approved and stops dispatching when no more approvals remain', function (): void {
    Queue::fake();

    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);
    $task->approve('ok', $approver);
    $task = $task->fresh();

    Event::fake();

    (new ApproveTaskJob($task, 100))->handle();

    // No follow-up jobs pushed AFTER our event fake
    Event::assertDispatched(ApproveTaskJobCompleted::class);

    expect($task->fresh()->status)->toBe(ApprovalStatus::APPROVED);
});

// 验证: job 应用 model 变更时套在 forceRun 中, 防止 ShouldBeApproved 又产生新的 ApprovalTask (递归保护)
it('runs affect inside ApprovalFacade::forceRun to avoid recursive approval', function (): void {
    Queue::fake();

    [$applicant, $approver] = User::newModels(2);
    actingAs($applicant);

    registerArticleFlowFor($approver);

    $article = Article::newModel();
    $article->title = 'change';
    $article->save();

    $task = ApprovalTask::query()->latest('id')->first();
    $task->approve('ok', $approver);
    $task = $task->fresh();

    $tasksBefore = ApprovalTask::query()->count();

    (new ApproveTaskJob($task, 100))->handle();

    // No new approval task should be created when ApproveTaskJob applies changes
    expect(ApprovalTask::query()->count())->toBe($tasksBefore);
});

// 验证: displayName 拼接为 "ApproveTaskJob #<task id> (<page>)"; 方便 Horizon/log 区分页码
it('computes display name with task id and page', function (): void {
    Queue::fake();

    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    $job = new ApproveTaskJob($task, 100, 3);

    expect($job->displayName())->toBe("ApproveTaskJob #{$task->getKey()} (3)");
});
