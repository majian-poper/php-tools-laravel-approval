<?php

/**
 * 测试目的: 覆盖 src/Exceptions/NoApproverForCreationException 的默认 message
 * 覆盖范围: 无参构造时返回固定的人类可读提示
 */

use PHPTools\Approval\Exceptions\NoApproverForCreationException;

// 验证: 无参构造默认 message 为 "No approver defined for approval task creation."
it('builds default message', function (): void {
    $exception = new NoApproverForCreationException;

    expect($exception->getMessage())->toBe('No approver defined for approval task creation.');
});
