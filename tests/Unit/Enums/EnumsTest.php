<?php

/**
 * 测试目的: 覆盖 src/Enums 下 ApprovalStatus、ApprovalFlowType、ApprovableEvent 三个枚举
 * 覆盖范围: cases() 数量与成员、options() label 映射、ApprovableEvent::tryFromActionName 的方法名/withTrashed 分支
 */

use PHPTools\Approval\Enums\ApprovableEvent;
use PHPTools\Approval\Enums\ApprovalFlowType;
use PHPTools\Approval\Enums\ApprovalStatus;

// 验证: ApprovalStatus 共 6 个 case (含 ROLLING_BACK/ROLLED_BACK)
it('exposes all approval status cases', function (): void {
    expect(ApprovalStatus::cases())
        ->toHaveCount(6)
        ->toContain(ApprovalStatus::PENDING)
        ->toContain(ApprovalStatus::APPROVING)
        ->toContain(ApprovalStatus::APPROVED)
        ->toContain(ApprovalStatus::REJECTED)
        ->toContain(ApprovalStatus::ROLLING_BACK)
        ->toContain(ApprovalStatus::ROLLED_BACK);
});

// 验证: ApprovalStatus::options() 提供 UI 友好的 value=>label 映射, 顺序固定
it('returns options for approval status', function (): void {
    expect(ApprovalStatus::options())->toBe([
        'pending' => 'Pending',
        'approving' => 'Approving',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'rolling_back' => 'Rolling back',
        'rolled_back' => 'Rolled back',
    ]);
});

// 验证: ApprovalFlowType 只有 EVERY/ANY 两个 case
it('exposes approval flow type cases', function (): void {
    expect(ApprovalFlowType::cases())
        ->toHaveCount(2)
        ->toContain(ApprovalFlowType::EVERY)
        ->toContain(ApprovalFlowType::ANY);
});

// 验证: ApprovalFlowType::options() 返回 "every=>Every","any=>Any"
it('returns options for approval flow type', function (): void {
    expect(ApprovalFlowType::options())->toBe([
        'every' => 'Every',
        'any' => 'Any',
    ]);
});

// 验证: ApprovableEvent 包含 CREATING/UPDATING/RESTORING/TRASHING/FORCE_DELETING 五个 case
it('exposes all approvable event cases', function (): void {
    expect(ApprovableEvent::cases())->toHaveCount(5)
        ->toContain(ApprovableEvent::CREATING)
        ->toContain(ApprovableEvent::UPDATING)
        ->toContain(ApprovableEvent::RESTORING)
        ->toContain(ApprovableEvent::TRASHING)
        ->toContain(ApprovableEvent::FORCE_DELETING);
});

// 验证: ApprovableEvent::options() 含 "trashing=>Soft deleting"、"force-deleting=>Force deleting" 等命名约定
it('returns options for approvable event', function (): void {
    expect(ApprovableEvent::options())->toBe([
        'creating' => 'Creating',
        'updating' => 'Updating',
        'restoring' => 'Restoring',
        'trashing' => 'Soft deleting',
        'force-deleting' => 'Force deleting',
    ]);
});

// 验证: tryFromActionName 的数据驱动分支; 预期: delete+withTrashed=true 走 TRASHING, =false 走 FORCE_DELETING
it('maps action names to approvable events', function (ApprovableEvent $expected, string $method, bool $withTrashed): void {
    expect(ApprovableEvent::tryFromActionName($method, $withTrashed))->toBe($expected);
})->with([
    'create' => [ApprovableEvent::CREATING, 'create', false],
    'update' => [ApprovableEvent::UPDATING, 'update', false],
    'restore' => [ApprovableEvent::RESTORING, 'restore', false],
    'delete with trashed' => [ApprovableEvent::TRASHING, 'delete', true],
    'delete without trashed' => [ApprovableEvent::FORCE_DELETING, 'delete', false],
    'forceDelete' => [ApprovableEvent::FORCE_DELETING, 'forceDelete', false],
]);

// 验证: 非法 action name 时返回 null 而不是抛错
it('returns null for unknown action name', function (): void {
    expect(ApprovableEvent::tryFromActionName('unknown'))->toBeNull();
});
