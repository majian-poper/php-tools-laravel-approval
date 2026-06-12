<?php

/**
 * 测试目的: 端到端覆盖 ApprovalTask 从 pending→approving/rejected 的状态机, 含 EVERY/ANY/分组 step 的判定
 * 覆盖范围: EVERY 流全员通过后才 approving、单人拒绝即 rejected、ANY 流一人通过即可、过期/重复操作/非授权 user 的异常、order_number 分组 (group step) 的"每组一人即可"语义
 */

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPTools\Approval\Enums\ApprovalFlowType;
use PHPTools\Approval\Events\ApprovalTaskApproved;
use PHPTools\Approval\Events\ApprovalTaskRejected;
use PHPTools\Approval\Exceptions\ApproverNotMatchException;
use PHPTools\Approval\Exceptions\ChangeStatusFailedException;
use PHPTools\Approval\Exceptions\UnexpectedApprovalStatusException;
use PHPTools\Approval\Jobs\ApproveTaskJob;
use PHPTools\Approval\Models\ApprovalStep;

// 验证: EVERY 流前 N-1 人 approve 返回 false 表示"还差人"; 全员通过后状态翻为 approving 并派发 ApproveTaskJob
it('marks a task as approving when all approvers approve (EVERY flow)', function (): void {
    Event::fake([ApprovalTaskApproved::class]);
    Queue::fake();

    [$applicant, $approver1, $approver2] = User::newModels(3);

    $task = buildTaskFor($applicant, [$approver1, $approver2]);

    expect($task->isPending())->toBeTrue();

    expect($task->fresh()->approve('ok', $approver1))->toBeFalse();
    expect($task->fresh()->approve('ok', $approver2))->toBeTrue();

    $task = $task->fresh();

    expect($task->status->value)->toBe('approving')
        ->and($task->approved_at)->toBeNull();

    Event::assertDispatched(ApprovalTaskApproved::class);
    Queue::assertPushed(ApproveTaskJob::class);
});

// 验证: EVERY 流中任一 reject 立刻终结流程为 rejected, 并 dispatch ApprovalTaskRejected
it('marks a task as rejected when any approver rejects (EVERY flow)', function (): void {
    Event::fake([ApprovalTaskRejected::class]);

    [$applicant, $approver1, $approver2] = User::newModels(3);

    $task = buildTaskFor($applicant, [$approver1, $approver2]);

    expect($task->fresh()->reject('not ok', $approver1))->toBeTrue();

    $task = $task->fresh();

    expect($task->status->value)->toBe('rejected');

    Event::assertDispatched(ApprovalTaskRejected::class);
});

// 验证: ANY 流中第一个 approve 就足够; 预期: 该 step 写入 user_id/approved_at, approve() 返回 true
it('treats a single approval as sufficient in an ANY flow', function (): void {
    Queue::fake();

    [$applicant, $approver1, $approver2] = User::newModels(3);

    $task = buildTaskFor($applicant, [$approver1, $approver2], ApprovalFlowType::ANY);

    expect($task->approve('ok', $approver2))->toBeTrue();

    /** @var ApprovalStep $approvedStep */
    $approvedStep = $task->steps()->whereApproved()->first();

    expect($approvedStep)->not()->toBeNull()
        ->and($approvedStep->user_id)->toBe($approver2->getKey())
        ->and($approvedStep->approved_at)->not()->toBeNull();
});

// 验证: 同一 step 在 approve 之后再调用 reject 应抛 UnexpectedApprovalStatusException
it('throws when a step is rejected after being approved', function (): void {
    [$applicant, $approver] = User::newModels(2);

    $task = buildTaskFor($applicant, [$approver]);

    $step = $task->steps->first();
    $step->approveBy($approver, 'first');

    // The second mutation must throw because the step is no longer pending.
    expect(static fn(): mixed => $step->fresh()->rejectBy($approver, 'again'))
        ->toThrow(UnexpectedApprovalStatusException::class);
});

// 验证: Carbon::setTestNow 推进到 expires_at 之后, approve 抛 ChangeStatusFailedException
it('throws when the task is expired', function (): void {
    [$applicant, $approver] = User::newModels(2);

    $task = buildTaskFor($applicant, [$approver]);

    Carbon::setTestNow(Carbon::now()->addDays(8));

    expect($task->canChangeStatus())->toBeFalse()
        ->and($task->isPending())->toBeTrue()
        ->and($task->isExpired())->toBeTrue();

    expect(static fn(): mixed => $task->fresh()->approve('', $approver))
        ->toThrow(ChangeStatusFailedException::class);

    Carbon::setTestNow();
});

// 验证: 非授权 stranger 调 approveBy 抛 ApproverNotMatchException, 与 step 一致行为
it('throws ApproverNotMatchException when an unrelated user approves a step', function (): void {
    [$applicant, $approver, $stranger] = User::newModels(3);

    $task = buildTaskFor($applicant, [$approver]);

    expect(static fn(): mixed => $task->steps->first()->approveBy($stranger, ''))
        ->toThrow(ApproverNotMatchException::class);
});

// 验证: buildTaskFor 中 [[$l1a,$l1b], $l2] 创建出 3 个 step, order_number 分别 [1,1,2]
it('groups approvers sharing the same order_number when building steps', function (): void {
    $applicant = User::newModel();
    [$l1a, $l1b, $l2] = User::newModels(3);

    $task = buildTaskFor($applicant, [[$l1a, $l1b], $l2]);

    $steps = $task->steps()->orderBy('order_number')->orderBy('id')->get();

    expect($steps)->toHaveCount(3)
        ->and($steps->pluck('order_number')->all())->toBe([1, 1, 2])
        ->and($steps->pluck('approver_id')->all())->toBe([$l1a->getKey(), $l1b->getKey(), $l2->getKey()]);
});

// 验证: 共享 order_number 的 step 是一个 group, group 内一人通过即满足该 group, EVERY 仍要求各 group 都满足
it('treats one approval per group as sufficient under EVERY flow (group = shared order_number)', function (): void {
    Queue::fake();

    $applicant = User::newModel();
    [$l1a, $l1b, $l2] = User::newModels(3);

    $task = buildTaskFor($applicant, [[$l1a, $l1b], $l2]);

    // l1a satisfies group 1, l2 satisfies group 2.
    expect($task->approve('l1a ok', $l1a))->toBeFalse()
        ->and($task->approve('l2 ok', $l2))->toBeTrue();

    $steps = $task->steps()->orderBy('order_number')->orderBy('id')->get();

    // Group 1: l1a approved, l1b still pending. Group 2: l2 approved.
    expect($l1aStep = $steps->firstwhere('approver_id', $l1a->getKey()))->not->toBeNull()
        ->and($l1aStep->isApproved())->toBeTrue();

    expect($l1bStep = $steps->firstwhere('approver_id', $l1b->getKey()))->not->toBeNull()
        ->and($l1bStep->isPending())->toBeTrue();

    expect($l2Step = $steps->firstwhere('approver_id', $l2->getKey()))->not->toBeNull()
        ->and($l2Step->isApproved())->toBeTrue();
});
