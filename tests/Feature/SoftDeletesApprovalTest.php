<?php

/**
 * 测试目的: 覆盖 SoftDeletes 模型 (Product) 经过 ShouldBeApproved trait 时 delete/forceDelete/restore 的拦截行为
 * 覆盖范围: delete 转为 TRASHING 任务、已 trashed 模型 forceDelete 转为 FORCE_DELETING、restore 转为 RESTORING、shouldBeApproved=false 时不创建 task 而走真实 delete
 */

use App\Models\Product;
use App\Models\User;
use PHPTools\Approval\Enums\ApprovableEvent;
use PHPTools\Approval\Enums\ApprovalFlowType;
use PHPTools\Approval\Facades\ApprovalFacade;
use PHPTools\Approval\Models\ApprovalFlow;
use PHPTools\Approval\Models\ApprovalFlowStep;
use PHPTools\Approval\Models\ApprovalTask;

use function Pest\Laravel\actingAs;

function registerProductFlowFor(User $approver): ApprovalFlow
{
    $flow = ApprovalFlow::query()->create([
        'name' => 'product-flow',
        'approvable_type' => (new Product)->getMorphClass(),
        'expiration' => 3600,
        'flow_type' => ApprovalFlowType::EVERY->value,
    ]);

    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->getKey(),
        'order_number' => 1,
        'approver_id' => $approver->getKey(),
        'approver_type' => $approver->getMorphClass(),
    ]);

    return $flow;
}

// 验证: 对 SoftDeletes 模型 delete() 不会真删, 而是创建 TRASHING task; 预期: 记录仍可被 find()
it('redirects delete() to a trashing approval task for SoftDeletes model', function (): void {
    [$applicant, $approver] = User::newModels(2);
    actingAs($applicant);
    registerProductFlowFor($approver);

    $product = Product::newModel();
    $product->delete();

    $task = ApprovalTask::query()->latest('id')->first();
    $approval = $task->approvals->first();

    expect($approval->event)->toBe(ApprovableEvent::TRASHING)
        ->and(Product::query()->find($product->getKey()))->not->toBeNull();
});

// 验证: 已 trashed 模型再 forceDelete() 创建 FORCE_DELETING task; 预期: 记录在 withTrashed 下仍存在
it('creates a force-deleting approval task for already trashed model', function (): void {
    [$applicant, $approver] = User::newModels(2);
    actingAs($applicant);
    registerProductFlowFor($approver);

    $product = Product::newModel();
    ApprovalFacade::forceRun(fn() => $product->delete()); // bypass approval → really soft-delete

    $product->forceDelete();

    $task = ApprovalTask::query()->latest('id')->first();
    $approval = $task->approvals->first();

    expect($approval->event)->toBe(ApprovableEvent::FORCE_DELETING)
        ->and(Product::withTrashed()->find($product->getKey()))->not->toBeNull();
});

// 验证: trashed 模型 restore() 创建 RESTORING task, 仍保持 trashed 状态 (find 返回 null)
it('creates a restoring approval task for trashed model restore', function (): void {
    [$applicant, $approver] = User::newModels(2);
    actingAs($applicant);
    registerProductFlowFor($approver);

    $product = Product::newModel();
    ApprovalFacade::forceRun(fn() => $product->delete());

    $product->restore();

    $task = ApprovalTask::query()->latest('id')->first();
    $approval = $task->approvals->first();

    expect($approval->event)->toBe(ApprovableEvent::RESTORING)
        ->and(Product::query()->find($product->getKey()))->toBeNull(); // still trashed
});

// 验证: ApprovalFacade::forceRun 内 delete 不会进入审批流, 直接落到 DB; 预期: tasks 计数不变, 记录被真删
it('does not create a task when shouldBeApproved is false', function (): void {
    [$applicant, $approver] = User::newModels(2);
    actingAs($applicant);
    registerProductFlowFor($approver);

    $product = Product::newModel();
    $tasksBefore = ApprovalTask::query()->count();

    ApprovalFacade::forceRun(fn() => $product->delete());

    expect(ApprovalTask::query()->count())->toBe($tasksBefore)
        ->and(Product::query()->find($product->getKey()))->toBeNull();
});
