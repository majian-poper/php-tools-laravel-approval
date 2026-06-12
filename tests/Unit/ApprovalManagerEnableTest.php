<?php

/**
 * 测试目的: 覆盖 src/ApprovalManager 的开关语义 (config + 运行时 flag + console 模式 + forceRun 上下文)
 * 覆盖范围: HTTP 默认开启、enable(false) 关闭、approval.enabled 配置、enabled_in_console、forceRun 临时关闭与嵌套、运行时 flag 优先级
 */

use PHPTools\Approval\ApprovalManager;

beforeEach(function (): void {
    config()->set('approval.enabled_in_console', true);
    config()->set('approval.enabled', true);
});

// 验证: beforeEach 已强制 enabled+enabled_in_console=true; 预期: isEnabled() 直接返回 true
it('is enabled by default in HTTP context', function (): void {
    $manager = app(ApprovalManager::class);

    expect($manager->isEnabled())->toBeTrue();
});

// 验证: 运行时 enable(false) 立即生效; 预期: 不依赖 config 即可关闭
it('can be disabled via enable(false)', function (): void {
    $manager = app(ApprovalManager::class);
    $manager->enable(false);

    expect($manager->isEnabled())->toBeFalse();
});

// 验证: approval.enabled=false 时无视其他条件; 预期: isEnabled() 为 false
it('respects config approval.enabled flag', function (): void {
    config()->set('approval.enabled', false);

    expect(app(ApprovalManager::class)->isEnabled())->toBeFalse();
});

// 验证: console 上下文里若 enabled_in_console=false 则关闭; 预期: 即便 enabled=true 也返回 false
it('is disabled in console when enabled_in_console is false', function (): void {
    config()->set('approval.enabled', true);
    config()->set('approval.enabled_in_console', false);

    expect(app(ApprovalManager::class)->isEnabled())->toBeFalse();
});

// 验证: console 上下文显式打开后行为同 HTTP; 预期: 两个 flag 都 true 才启用
it('is enabled in console when enabled_in_console is true', function (): void {
    config()->set('approval.enabled', true);
    config()->set('approval.enabled_in_console', true);

    expect(app(ApprovalManager::class)->isEnabled())->toBeTrue();
});

// 验证: forceRun 闭包内 isEnabled 为 false, 闭包结束后恢复; 预期: 返回值原样透传
it('disables temporarily inside forceRun and restores after', function (): void {
    $manager = app(ApprovalManager::class);

    expect($manager->isEnabled())->toBeTrue();

    $inside = null;
    $result = $manager->forceRun(function () use ($manager, &$inside): mixed {
        $inside = $manager->isEnabled();

        return 'ok';
    });

    expect($inside)->toBeFalse()
        ->and($result)->toBe('ok')
        ->and($manager->isEnabled())->toBeTrue();
});

// 验证: 已经 enable(false) 时嵌套 forceRun 不会意外恢复为 true; 预期: 始终为 false
it('preserves the original enable state when forceRun is nested', function (): void {
    $manager = app(ApprovalManager::class);
    $manager->enable(false);

    $manager->forceRun(function () use ($manager): void {
        expect($manager->isEnabled())->toBeFalse();

        $manager->forceRun(function () use ($manager): void {
            expect($manager->isEnabled())->toBeFalse();
        });

        expect($manager->isEnabled())->toBeFalse();
    });

    expect($manager->isEnabled())->toBeFalse();
});

// 验证: 运行时 flag 优先级高于 config; 预期: config 为 true 时仍然返回 false
it('returns false when the runtime flag is false even if config is true', function (): void {
    config()->set('approval.enabled', true);

    $manager = app(ApprovalManager::class);
    $manager->enable(false);

    expect($manager->isEnabled())->toBeFalse();
});
