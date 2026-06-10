<?php

/**
 * 测试目的: 覆盖 src/Exceptions/ChangeStatusFailedException 的 reason 分支与 message 组装
 * 覆盖范围: expired / not-pending / 权限不足三种 reason 分支; 完整 message 含 task id、目标 status、user identifier
 */

use App\Models\User;
use Illuminate\Support\Carbon;
use PHPTools\Approval\Enums\ApprovalStatus;
use PHPTools\Approval\Exceptions\ChangeStatusFailedException;

// 验证: 优先级最高的 reason 为过期; 预期: getReason 返回 "Approval task is expired."
it('reports expired reason when task is expired', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    Carbon::setTestNow(now()->addDays(2));

    $exception = new ChangeStatusFailedException($task, ApprovalStatus::APPROVED, $applicant);

    expect($exception->getReason())->toBe('Approval task is expired.')
        ->and($exception->getMessage())->toContain('Approval task is expired.');

    Carbon::setTestNow();
});

// 验证: task 已 APPROVED 时无法再变更; 预期: reason 为 "Approval task is not pending."
it('reports not-pending reason when task is no longer pending', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);
    $task->markAsApproved()->save();

    $exception = new ChangeStatusFailedException($task, ApprovalStatus::REJECTED, $applicant);

    expect($exception->getReason())->toBe('Approval task is not pending.');
});

// 验证: task 仍 pending 但用户不在 approvers 中; 预期: reason 指出权限不足
it('reports permission reason when task is pending but user lacks permission', function (): void {
    [$applicant, $approver, $stranger] = User::newModels(3);
    $task = buildTaskFor($applicant, [$approver]);

    $exception = new ChangeStatusFailedException($task, ApprovalStatus::APPROVED, $stranger);

    expect($exception->getReason())->toBe('User does not have permission to change status.');
});

// 验证: 异常公开 approvalTask/toStatus/user; 预期: message 含 task id、status 字符串、用户 identifier
it('builds the full message with task id, target status, user identifiers and reason', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    $exception = new ChangeStatusFailedException($task, ApprovalStatus::APPROVED, $approver);

    expect($exception->approvalTask)->toBe($task)
        ->and($exception->toStatus)->toBe(ApprovalStatus::APPROVED)
        ->and($exception->user)->toBe($approver)
        ->and($exception->getMessage())->toContain("[{$task->getKey()}]")
        ->and($exception->getMessage())->toContain('approved')
        ->and($exception->getMessage())->toContain((string) $approver->getAuthIdentifier());
});
