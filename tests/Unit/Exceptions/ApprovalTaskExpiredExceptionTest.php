<?php

/**
 * 测试目的: 覆盖 src/Exceptions/ApprovalTaskExpiredException 的 message 与公开属性
 * 覆盖范围: message 包含 task 主键与 expires_at 字符串; approvalTask 属性可访问原对象
 */

use App\Models\User;
use Illuminate\Support\Carbon;
use PHPTools\Approval\Exceptions\ApprovalTaskExpiredException;

// 验证: 异常 message 同时包含 task id 和 expires_at; 预期: approvalTask 属性指向原 task 实例
it('builds message with task id and expires_at', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    Carbon::setTestNow(now()->addDays(2));

    $exception = new ApprovalTaskExpiredException($task);

    expect($exception->getMessage())->toContain("Approval task [{$task->getKey()}] was expired at")
        ->and($exception->getMessage())->toContain($task->expires_at->toDateTimeString())
        ->and($exception->approvalTask)->toBe($task);

    Carbon::setTestNow();
});
