<?php

/**
 * 测试目的: 覆盖 src/Models/ApprovalTask 的 fillable/cast、过期判定、权限/回滚分支
 * 覆盖范围: resolver 驱动的 fillable&cast、isExpired、canChangeStatus、canStatusBeChangedBy、canBeRolledBack(By)、rollBack 三种 throw 分支、已审批用户被排除
 */

use App\Models\User;
use Illuminate\Support\Carbon;
use PHPTools\Approval\Exceptions\RollBackFailedException;
use PHPTools\Approval\Models\ApprovalTask;

// 验证: fillable 既包含基础列也包含 resolver 注册的 ip_address/user_agent/url
it('exposes fillable columns including custom resolver-driven ones', function (): void {
    $task = new ApprovalTask;

    expect($task->getFillable())->toContain('title', 'description', 'flow_type', 'status', 'expires_at')
        ->and($task->getFillable())->toContain('ip_address', 'user_agent', 'url');
});

// 验证: getCasts 包含枚举与 resolver 声明的 attributeCast (此处只断言 key 存在)
it('casts custom resolver columns according to the resolver attributeCast', function (): void {
    $task = new ApprovalTask;

    expect($task->getCasts())->toHaveKey('flow_type')
        ->and($task->getCasts())->toHaveKey('status')
        ->and($task->getCasts())->toHaveKey('ip_address');
});

// 验证: Carbon::setTestNow 推进到 expires_at 之后, isExpired 翻转为 true
it('isExpired() returns true after expires_at has passed', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    expect($task->isExpired())->toBeFalse();

    Carbon::setTestNow(now()->addDays(2));

    expect($task->isExpired())->toBeTrue();

    Carbon::setTestNow();
});

// 验证: canChangeStatus 要求未过期且状态为 pending; 预期: 被 markAsRejected 后变 false
it('canChangeStatus() requires not expired and pending', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    expect($task->canChangeStatus())->toBeTrue();

    $task->markAsRejected()->save();

    expect($task->canChangeStatus())->toBeFalse();
});

// 验证: approver 在审批人列表中可改, 无关 stranger 不可
it('canStatusBeChangedBy() returns false for stranger users', function (): void {
    [$applicant, $approver, $stranger] = User::newModels(3);
    $task = buildTaskFor($applicant, [$approver]);

    expect($task->canStatusBeChangedBy($approver))->toBeTrue()
        ->and($task->canStatusBeChangedBy($stranger))->toBeFalse();
});

// 验证: 只有 APPROVED 且未 ROLLED_BACK 才可回滚; 预期: pending→false, approved→true, rolled_back→false
it('canBeRolledBack() requires approved and not yet rolled back', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    expect($task->canBeRolledBack())->toBeFalse();

    $task->markAsApproved()->save();

    expect($task->canBeRolledBack())->toBeTrue();

    $task->markAsRolledBack()->save();

    expect($task->canBeRolledBack())->toBeFalse();
});

// 验证: workbench User canRollBack 仅当 id===1 时为 true; applicant(id=1) 可回滚, approver(id=2) 不可
it('canBeRolledBackBy() requires Approver instance with canRollBack=true', function (): void {
    // workbench User: canRollBack() === (getKey() === 1)
    [$applicant, $approver] = User::newModels(2);

    $task = buildTaskFor($applicant, [$approver]);
    $task->markAsApproved()->save();

    // applicant has id=1, so canRollBack returns true
    expect($task->canBeRolledBackBy($applicant))->toBeTrue()
        ->and($task->canBeRolledBackBy($approver))->toBeFalse();
});

// 验证: rollBack 时由 approver(无权限) 触发 RollBackFailedException
it('rollBack() throws RollBackFailedException when user lacks permission', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);
    $task->markAsApproved()->save();

    // approver has id=2 → canRollBack=false
    expect(static fn() => $task->rollBack($approver))
        ->toThrow(RollBackFailedException::class);
});

// 验证: 还未 APPROVED 的 task 调 rollBack 直接抛 RollBackFailedException
it('rollBack() throws when task is not approved', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    expect(static fn() => $task->rollBack($applicant))
        ->toThrow(RollBackFailedException::class);
});

// 验证: 已 ROLLED_BACK 不能再次 rollBack; 预期: 抛 RollBackFailedException
it('rollBack() throws when task is already rolled back', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);
    $task->markAsApproved()->save();
    $task->markAsRolledBack()->save();

    expect(static fn() => $task->rollBack($applicant))
        ->toThrow(RollBackFailedException::class);
});

// 验证: approver 已审完该 step 后再次询问 canStatusBeChangedBy 应返回 false (避免重复审)
it('returns no affectable steps when user has already reviewed', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    $step = $task->steps->first();
    $step->approveBy($approver);

    $task->load('steps');

    // after approving, the user has reviewed; canStatusBeChangedBy should be false
    expect($task->canStatusBeChangedBy($approver))->toBeFalse();
});
