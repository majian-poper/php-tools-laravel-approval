<?php

/**
 * 测试目的: 覆盖 src/Exceptions/UnauthorizedException 的继承链与 message 透传
 * 覆盖范围: 是 RuntimeException 子类、构造参数原样作为 message
 */

use PHPTools\Approval\Exceptions\UnauthorizedException;

// 验证: 继承自 RuntimeException; 预期: 传入的 'forbidden' 完整保留为 message
it('is a RuntimeException subclass', function (): void {
    $exception = new UnauthorizedException('forbidden');

    expect($exception)->toBeInstanceOf(\RuntimeException::class)
        ->and($exception->getMessage())->toBe('forbidden');
});
