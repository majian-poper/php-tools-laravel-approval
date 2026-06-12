<?php

/**
 * 测试目的: 覆盖 src/Exceptions/RollBackFailedException 的 reason 分支与 message 组装
 * 覆盖范围: 已回滚 / 未审批通过 / 权限不足三种 reason; 完整 message 含 task id、user identifier
 */

use App\Models\User;
use PHPTools\Approval\Exceptions\RollBackFailedException;

// 验证: 已 ROLLED_BACK 时不可再次回滚; 预期: reason 包含 "Already rolled back at"
it('reports "already rolled back" reason when task is rolled back', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);
    $task->markAsApproved()->save();
    $task->markAsRolledBack()->save();

    $exception = new RollBackFailedException($task, $applicant);

    expect($exception->getReason())->toContain('Already rolled back at');
});

// 验证: task 未到 APPROVED 时拒绝回滚; 预期: reason 为 "Only approved task can be rolled back"
it('reports "only approved task" reason when task is not approved', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    $exception = new RollBackFailedException($task, $applicant);

    expect($exception->getReason())->toBe('Only approved task can be rolled back');
});

// 验证: User 仅 id=1 可回滚; approver(id=2) 触发权限不足分支
it('reports "no permission" reason when task is approved but user lacks permission', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);
    $task->markAsApproved()->save();

    $exception = new RollBackFailedException($task, $approver);

    expect($exception->getReason())->toBe('User does not have permission to roll back.');
});

// 验证: 异常公开 approvalTask/user; 预期: message 同时包含 task id、用户 identifier、reason 文案
it('builds full message containing task id, user identifiers and reason', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);
    $task->markAsApproved()->save();

    $exception = new RollBackFailedException($task, $approver);

    expect($exception->approvalTask)->toBe($task)
        ->and($exception->user)->toBe($approver)
        ->and($exception->getMessage())->toContain("[{$task->getKey()}]")
        ->and($exception->getMessage())->toContain((string) $approver->getAuthIdentifier())
        ->and($exception->getMessage())->toContain('User does not have permission to roll back.');
});
