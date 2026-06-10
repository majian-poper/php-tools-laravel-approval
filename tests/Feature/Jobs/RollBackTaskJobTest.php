<?php

/**
 * 测试目的: 覆盖 src/Jobs/RollBackTaskJob 的回滚分块、状态守卫、事件触发与 old_values 复原
 * 覆盖范围: 非 rolling_back 时早退、UPDATING approval 回滚还原旧值并 dispatch ApprovalsRollingBack/RolledBack/JobCompleted、chunkSize 分页时 re-dispatch page=2、displayName 拼装
 */

use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPTools\Approval\Enums\ApprovalFlowType;
use PHPTools\Approval\Enums\ApprovalStatus;
use PHPTools\Approval\Events\ApprovalsRolledBack;
use PHPTools\Approval\Events\ApprovalsRollingBack;
use PHPTools\Approval\Events\RollBackTaskJobCompleted;
use PHPTools\Approval\Facades\ApprovalFacade;
use PHPTools\Approval\Jobs\ApproveTaskJob;
use PHPTools\Approval\Jobs\RollBackTaskJob;
use PHPTools\Approval\Models\ApprovalFlow;
use PHPTools\Approval\Models\ApprovalFlowStep;
use PHPTools\Approval\Models\ApprovalTask;
use PHPTools\Approval\SimpleFlow;

use function Pest\Laravel\actingAs;

function registerArticleFlowForRollback(User $approver): ApprovalFlow
{
    $flow = ApprovalFlow::query()->create([
        'name' => 'rollback-article',
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

// 验证: task 仍 PENDING 时 job 早退, 不 dispatch RollingBack/Completed 事件
it('returns early when task is not in rolling back state', function (): void {
    Event::fake();

    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    (new RollBackTaskJob($task, 100))->handle();

    Event::assertNotDispatched(ApprovalsRollingBack::class);
    Event::assertNotDispatched(RollBackTaskJobCompleted::class);

    expect($task->fresh()->status)->toBe(ApprovalStatus::PENDING);
});

// 验证: 审批后再 rollBack 能恢复 article 原 title; 预期: 状态 ROLLED_BACK, dispatch 三事件, approval.rolled_back_at 写入
it('rolls back UPDATING approvals and restores old values', function (): void {
    [$approver, $applicant] = User::newModels(2); // approver is id=1 → canRollBack true
    actingAs($applicant);

    registerArticleFlowForRollback($approver);

    $article = Article::newModel();
    $oldTitle = $article->title;

    $article->title = 'Updated title';
    $article->save();

    $task = ApprovalTask::query()->latest('id')->first();
    $task->approve('ok', $approver); // sync queue: ApproveTaskJob applies the change

    expect($task->fresh()->status)->toBe(ApprovalStatus::APPROVED)
        ->and($article->fresh()->title)->toBe('Updated title');

    Queue::fake();
    Event::fake();

    $task = $task->fresh();
    $task->rollBack($approver);

    expect($task->fresh()->status)->toBe(ApprovalStatus::ROLLING_BACK);

    (new RollBackTaskJob($task->fresh(), 100))->handle();

    Event::assertDispatched(ApprovalsRollingBack::class);
    Event::assertDispatched(ApprovalsRolledBack::class);
    Event::assertDispatched(RollBackTaskJobCompleted::class);

    expect($task->fresh()->status)->toBe(ApprovalStatus::ROLLED_BACK)
        ->and($article->fresh()->title)->toBe($oldTitle);

    $approval = $task->fresh()->approvals->first();
    expect($approval->rolled_back_at)->not->toBeNull();
});

// 验证: 3 条 approval + chunkSize=2 时, 首批回滚 2 条并 re-dispatch page=2; 期间状态保持 ROLLING_BACK
it('chunks rollback approvals and re-dispatches itself when more remain', function (): void {
    [$approver, $applicant] = User::newModels(2);
    actingAs($applicant);

    $articles = Article::newModels(3);

    $flow = new SimpleFlow(
        title: 'T',
        description: 'D',
        type: ApprovalFlowType::EVERY,
        expiresAt: new DateTimeImmutable('+1 day'),
        approvers: [$approver],
    );

    $task = ApprovalFacade::createTask($articles, $flow, $applicant);
    $task->approve('ok', $approver); // sync queue effects all 3

    $task = $task->fresh();
    expect($task->status)->toBe(ApprovalStatus::APPROVED);

    Queue::fake();

    $task->rollBack($approver);

    // chunkSize 2 → first batch handles 2, then re-dispatches page 2
    (new RollBackTaskJob($task->fresh(), 2))->handle();

    Queue::assertPushed(RollBackTaskJob::class, function ($job): bool {
        return $job->page === 2;
    });

    expect($task->fresh()->status)->toBe(ApprovalStatus::ROLLING_BACK);
});

// 验证: displayName 格式 "RollBackTaskJob #<task id> (<page>)"
it('computes display name with task id and page', function (): void {
    Queue::fake();

    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    $job = new RollBackTaskJob($task, 100, 7);

    expect($job->displayName())->toBe("RollBackTaskJob #{$task->getKey()} (7)");
});
