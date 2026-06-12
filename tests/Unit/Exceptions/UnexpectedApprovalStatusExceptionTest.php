<?php

/**
 * 测试目的: 覆盖 src/Exceptions/UnexpectedApprovalStatusException 的 message 拼装
 * 覆盖范围: 期望/实际状态对照; 主体模型 morph class 与主键均出现在 message 中
 */

use PHPTools\Approval\Enums\ApprovalStatus;
use PHPTools\Approval\Exceptions\UnexpectedApprovalStatusException;
use PHPTools\Approval\Models\ApprovalStep;

// 验证: message 文本包含 "expected pending"/"but got approved" 以及目标模型 morph 与 key
it('builds message with expected status, actual status, morph class and key', function (): void {
    $step = new ApprovalStep(['status' => ApprovalStatus::APPROVED]);

    $exception = new UnexpectedApprovalStatusException(ApprovalStatus::PENDING, $step);

    expect($exception->getMessage())->toContain('expected pending')
        ->and($exception->getMessage())->toContain('but got approved')
        ->and($exception->getMessage())->toContain($step->getMorphClass())
        ->and($exception->getMessage())->toContain((string) $step->getKey());
});
