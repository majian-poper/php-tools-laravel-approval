<?php

/**
 * 测试目的: 覆盖 src/Models/Concerns 下 InteractsWithState 与 InteractsWithTransitionalState 两个 trait
 * 覆盖范围: markAsXxx 状态翻转与 approved_at/rolled_back_at 副作用、expectStatus 校验、whereStatus scope 绑定、APPROVING/ROLLING_BACK 等过渡态切换
 */

use PHPTools\Approval\Enums\ApprovalStatus;
use PHPTools\Approval\Exceptions\UnexpectedApprovalStatusException;
use PHPTools\Approval\Models\ApprovalStep;
use PHPTools\Approval\Models\ApprovalTask;

// 验证: markAsApproved/Rejected/Pending 翻转 status 并维护 approved_at; 预期: Pending 时 approved_at 被清空
it('flips between status values on InteractsWithState', function (): void {
    $model = new ApprovalStep(['status' => ApprovalStatus::PENDING]);

    $model->markAsApproved();
    expect($model->status)->toBe(ApprovalStatus::APPROVED)
        ->and($model->approved_at)->not->toBeNull();

    $model->markAsRejected();
    expect($model->status)->toBe(ApprovalStatus::REJECTED)
        ->and($model->approved_at)->not->toBeNull();

    $model->markAsPending();
    expect($model->status)->toBe(ApprovalStatus::PENDING)
        ->and($model->approved_at)->toBeNull();
});

// 验证: 状态匹配时 expectStatus 静默通过, 不抛 UnexpectedApprovalStatusException
it('does not throw when expectStatus matches', function (): void {
    $model = new ApprovalStep(['status' => ApprovalStatus::PENDING]);

    expect(static fn() => $model->expectStatus(ApprovalStatus::PENDING))
        ->not->toThrow(UnexpectedApprovalStatusException::class);
});

// 验证: 状态不匹配时 expectStatus 抛 UnexpectedApprovalStatusException
it('throws when expectStatus does not match', function (): void {
    /** @var ApprovalStep $model */
    $model = new ApprovalStep(['status' => ApprovalStatus::APPROVED]);

    expect(static fn() => $model->expectStatus(ApprovalStatus::PENDING))
        ->toThrow(UnexpectedApprovalStatusException::class);
});

// 验证: whereStatus scope 将枚举绑定为字符串占位符, 适用于 PENDING/APPROVED/REJECTED 三态
it('exposes state scopes on InteractsWithState', function (): void {
    $model = new ApprovalStep;

    // The whereIn SQL uses '?' placeholders, but bindings carry the values.
    $pending = $model->newQuery()->whereStatus(ApprovalStatus::PENDING);
    $approved = $model->newQuery()->whereStatus(ApprovalStatus::APPROVED);
    $rejected = $model->newQuery()->whereStatus(ApprovalStatus::REJECTED);

    expect($pending->getBindings())->toContain('pending')
        ->and($approved->getBindings())->toContain('approved')
        ->and($rejected->getBindings())->toContain('rejected');
});

// 验证: Task 上 markAsApproving/Approved/RollingBack/RolledBack 顺序流转; 预期: APPROVED 写入 approved_at, ROLLED_BACK 写入 rolled_back_at
it('flips between transitional status values on InteractsWithTransitionalState', function (): void {
    $model = new ApprovalTask(['status' => ApprovalStatus::PENDING]);

    $model->markAsApproving();
    expect($model->status)->toBe(ApprovalStatus::APPROVING)
        ->and($model->approved_at)->toBeNull()
        ->and($model->rolled_back_at)->toBeNull();

    $model->markAsApproved();
    expect($model->status)->toBe(ApprovalStatus::APPROVED)
        ->and($model->approved_at)->not->toBeNull()
        ->and($model->rolled_back_at)->toBeNull();

    $model->markAsRollingBack();
    expect($model->status)->toBe(ApprovalStatus::ROLLING_BACK)
        ->and($model->rolled_back_at)->toBeNull();

    $model->markAsRolledBack();
    expect($model->status)->toBe(ApprovalStatus::ROLLED_BACK)
        ->and($model->rolled_back_at)->not->toBeNull();
});

// 验证: 从 ROLLED_BACK 再次 markAsApproved/Rejected 时 rolled_back_at 被清空, 避免脏数据
it('clears rolled_back_at when transition states are reapplied', function (): void {
    $model = new ApprovalTask(['status' => ApprovalStatus::PENDING]);

    $model->markAsRolledBack();
    expect($model->rolled_back_at)->not->toBeNull();

    $model->markAsApproved();
    expect($model->rolled_back_at)->toBeNull();

    $model->markAsRejected();
    expect($model->rolled_back_at)->toBeNull();
});

// 验证: 过渡态 whereStatus 也通过同一 scope, 绑定值为对应字符串 (approving/rolling_back/rolled_back)
it('exposes transitional state scopes', function (): void {
    $model = new ApprovalTask(['status' => ApprovalStatus::PENDING]);

    // The transitional scopes delegate to whereStatus under the hood.
    $approving = $model->newQuery()->whereStatus(ApprovalStatus::APPROVING);
    $rollingBack = $model->newQuery()->whereStatus(ApprovalStatus::ROLLING_BACK);
    $rolledBack = $model->newQuery()->whereStatus(ApprovalStatus::ROLLED_BACK);

    expect($approving->getBindings())->toContain('approving')
        ->and($rollingBack->getBindings())->toContain('rolling_back')
        ->and($rolledBack->getBindings())->toContain('rolled_back');
});
