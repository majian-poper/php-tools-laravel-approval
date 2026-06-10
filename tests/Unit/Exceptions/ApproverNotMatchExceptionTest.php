<?php

/**
 * 测试目的: 覆盖 src/Exceptions/ApproverNotMatchException 的 message 拼装与属性暴露
 * 覆盖范围: 同时携带越权 user 与目标 step; message 包含双方 morph class、主键及 step morph
 */

use App\Models\User;
use PHPTools\Approval\Exceptions\ApproverNotMatchException;

// 验证: 异常公开 user/step 属性; 预期: message 同时包含 user/approver 的 morph 与 key, 以及 step morph
it('builds message with both user and approver morph info', function (): void {
    [$applicant, $approver, $stranger] = User::newModels(3);
    $task = buildTaskFor($applicant, [$approver]);
    $step = $task->steps->first();

    $exception = new ApproverNotMatchException($stranger, $step);

    expect($exception->user)->toBe($stranger)
        ->and($exception->step)->toBe($step)
        ->and($exception->getMessage())->toContain($stranger->getMorphClass())
        ->and($exception->getMessage())->toContain((string) $stranger->getKey())
        ->and($exception->getMessage())->toContain($step->approver->getMorphClass())
        ->and($exception->getMessage())->toContain((string) $step->approver->getKey())
        ->and($exception->getMessage())->toContain($step->getMorphClass());
});
