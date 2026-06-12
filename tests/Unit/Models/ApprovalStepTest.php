<?php

/**
 * 测试目的: 覆盖 src/Models/ApprovalStep (审批单 step) 的字段、关系、approveBy/rejectBy 行为
 * 覆盖范围: 属性 cast、task/approver/user 关系、contains/isReviewedBy、approveBy/rejectBy 成功路径 + 事件触发, 以及 UnexpectedApprovalStatusException 与 ApproverNotMatchException 两个异常分支
 */

use App\Models\User;
use Illuminate\Support\Facades\Event;
use PHPTools\Approval\Enums\ApprovalStatus;
use PHPTools\Approval\Events\ApprovalStepApproved;
use PHPTools\Approval\Events\ApprovalStepRejected;
use PHPTools\Approval\Exceptions\ApproverNotMatchException;
use PHPTools\Approval\Exceptions\UnexpectedApprovalStatusException;
use PHPTools\Approval\Models\ApprovalStep;
use PHPTools\Approval\Models\ApprovalTask;

function firstStepOf(ApprovalTask $task): ApprovalStep
{
    return $task->steps()->first();
}

// 验证: 主键/外键为 int, status 为 ApprovalStatus 枚举, 未审时 approved_at 为 null
it('casts attributes to expected types', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    $step = firstStepOf($task);

    expect($step->approval_task_id)->toBeInt()
        ->and($step->order_number)->toBeInt()
        ->and($step->approver_id)->toBeInt()
        ->and($step->status)->toBeInstanceOf(ApprovalStatus::class)
        ->and($step->approved_at)->toBeNull();
});

// 验证: task() 取出原 task, approver() morph 取出 User; 未关联实际审批 user 时 user() 为 null
it('resolves task, approver and user relations', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    $step = firstStepOf($task);

    expect($step->task)->toBeInstanceOf(ApprovalTask::class)
        ->and($step->task->getKey())->toBe($task->getKey())
        ->and($step->approver)->toBeInstanceOf(User::class)
        ->and($step->approver->getKey())->toBe($approver->getKey())
        ->and($step->user)->toBeNull();
});

// 验证: contains 判断给定 user 是否属于此 step 的 approver 群体
it('contains() delegates to approver', function (): void {
    [$applicant, $approver, $stranger] = User::newModels(3);
    $task = buildTaskFor($applicant, [$approver]);

    $step = firstStepOf($task);

    expect($step->contains($approver))->toBeTrue()
        ->and($step->contains($stranger))->toBeFalse();
});

// 验证: approveBy 后 isReviewedBy 返回 true; 预期: user_id 列被填上
it('isReviewedBy() returns true once user has been associated', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    $step = firstStepOf($task);

    expect($step->isReviewedBy($approver))->toBeFalse();

    $step->approveBy($approver);
    $step->refresh();

    expect($step->isReviewedBy($approver))->toBeTrue();
});

// 验证: 无人审过时 stranger 的 isReviewedBy 自然为 false
it('isReviewedBy() returns false when no user has reviewed yet', function (): void {
    [$applicant, $approver, $stranger] = User::newModels(3);
    $task = buildTaskFor($applicant, [$approver]);

    $step = firstStepOf($task);

    expect($step->isReviewedBy($stranger))->toBeFalse();
});

// 验证: approveBy 写入 status/comment/user_id, 返回 true 并 dispatch ApprovalStepApproved
it('approveBy() marks the step approved, stores comment and user, fires event', function (): void {
    Event::fake();

    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    $step = firstStepOf($task);

    $result = $step->approveBy($approver, 'looks good');

    expect($result)->toBeTrue()
        ->and($step->fresh()->status)->toBe(ApprovalStatus::APPROVED)
        ->and($step->fresh()->comment)->toBe('looks good')
        ->and($step->fresh()->user_id)->toBe($approver->getKey());

    Event::assertDispatched(ApprovalStepApproved::class);
});

// 验证: rejectBy 对称地写入 status/comment, dispatch ApprovalStepRejected
it('rejectBy() marks the step rejected, stores comment and user, fires event', function (): void {
    Event::fake();

    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    $step = firstStepOf($task);

    $result = $step->rejectBy($approver, 'nope');

    expect($result)->toBeTrue()
        ->and($step->fresh()->status)->toBe(ApprovalStatus::REJECTED)
        ->and($step->fresh()->comment)->toBe('nope');

    Event::assertDispatched(ApprovalStepRejected::class);
});

// 验证: 已审过的 step 再次 approveBy 时抛 UnexpectedApprovalStatusException, 防重复
it('approveBy() throws when status is not pending', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    $step = firstStepOf($task);
    $step->approveBy($approver);

    expect(static fn(): mixed => $step->approveBy($approver))
        ->toThrow(UnexpectedApprovalStatusException::class);
});

// 验证: 已审过的 step 再次 rejectBy 同样抛 UnexpectedApprovalStatusException
it('rejectBy() throws when status is not pending', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    $step = firstStepOf($task);
    $step->approveBy($approver);

    expect(static fn(): mixed => $step->rejectBy($approver))
        ->toThrow(UnexpectedApprovalStatusException::class);
});

// 验证: 非授权人 approveBy 抛 ApproverNotMatchException
it('approveBy() throws ApproverNotMatchException when user is not in approvers', function (): void {
    [$applicant, $approver, $stranger] = User::newModels(3);
    $task = buildTaskFor($applicant, [$approver]);

    $step = firstStepOf($task);

    expect(static fn(): mixed => $step->approveBy($stranger))
        ->toThrow(ApproverNotMatchException::class);
});

// 验证: 非授权人 rejectBy 抛 ApproverNotMatchException
it('rejectBy() throws ApproverNotMatchException when user is not in approvers', function (): void {
    [$applicant, $approver, $stranger] = User::newModels(3);
    $task = buildTaskFor($applicant, [$approver]);

    $step = firstStepOf($task);

    expect(static fn(): mixed => $step->rejectBy($stranger))
        ->toThrow(ApproverNotMatchException::class);
});
