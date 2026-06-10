<?php

/**
 * 测试目的: 覆盖 src/Models/ApprovalFlowStep (审批模板的步骤) 的 cast、外键写入、approver morph 关系
 * 覆盖范围: 属性类型 cast、approval_flow_id 外键正确写入、morph 解出原 User 实例
 */

use App\Models\User;
use PHPTools\Approval\Enums\ApprovalFlowType;
use PHPTools\Approval\Models\ApprovalFlow;
use PHPTools\Approval\Models\ApprovalFlowStep;

function makeFlowStepFor(User $approver, int $orderNumber = 1, ?ApprovalFlow $flow = null): ApprovalFlowStep
{
    $flow ??= ApprovalFlow::query()->create([
        'name' => 'F',
        'approvable_type' => 'article',
        'expiration' => 3600,
        'flow_type' => ApprovalFlowType::EVERY->value,
    ]);

    return ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->getKey(),
        'order_number' => $orderNumber,
        'approver_type' => $approver->getMorphClass(),
        'approver_id' => $approver->getKey(),
    ]);
}

// 验证: approval_flow_id/order_number/approver_id 为 int, approver_type 为 string
it('casts attributes to the expected types', function (): void {
    [$user] = User::newModels(1);
    $step = makeFlowStepFor($user, 3);

    expect($step->approval_flow_id)->toBeInt()
        ->and($step->order_number)->toBe(3)
        ->and($step->approver_id)->toBeInt()
        ->and($step->approver_type)->toBeString();
});

// 验证: flow() belongsTo 关系正确按 approval_flow_id 解出原 ApprovalFlow 实例
it('belongs to the parent flow via approval_flow_id', function (): void {
    [$user] = User::newModels(1);
    $flow = ApprovalFlow::query()->create([
        'name' => 'Parent flow',
        'approvable_type' => 'article',
        'expiration' => 3600,
        'flow_type' => ApprovalFlowType::EVERY->value,
    ]);

    $step = makeFlowStepFor($user, flow: $flow);

    expect($step->flow)->toBeInstanceOf(ApprovalFlow::class)
        ->and($step->flow->getKey())->toBe($flow->getKey())
        ->and($step->approval_flow_id)->toBe($flow->getKey());
});

// 验证: approver() morph 关系还原回原 User 实例
it('resolves approver via morph relation', function (): void {
    [$user] = User::newModels(1);
    $step = makeFlowStepFor($user);

    expect($step->approver)->toBeInstanceOf(User::class)
        ->and($step->approver->getKey())->toBe($user->getKey());
});
