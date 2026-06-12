<?php

/**
 * 测试目的: 覆盖 src/ShouldBeApproved trait (被审批模型的事件拦截与 Approval 快照构造)
 * 覆盖范围: approvals MorphMany 关系、getLabel 默认 fallback、requestFor 与 getRequestEvent 回读、CREATING/UPDATING/TRASHING/RESTORING/FORCE_DELETING 五种 event 下 toApprovalAttributes 的 old/new_values 构造、toApproval 绑定 morph
 */

use App\Models\Product;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use PHPTools\Approval\Enums\ApprovableEvent;
use PHPTools\Approval\Models\Approval;

// 验证: approvals() 是 MorphMany 且 morph type 列名为 approvable_type
it('exposes a polymorphic approvals relation', function (): void {
    $product = new Product;

    $relation = $product->approvals();

    expect($relation)->toBeInstanceOf(MorphMany::class)
        ->and($relation->getMorphType())->toBe('approvable_type')
        ->and($relation->getRelated())->toBeInstanceOf(Approval::class);
});

// 验证: getLabel 默认回退到表名 "products"; Product 未覆写时与 getTable 一致
it('returns table name as default getLabel', function (): void {
    $product = new Product;

    expect($product->getLabel())->toBe('products')
        ->and($product->getLabel())->toBe($product->getTable());
});

// 验证: getRequestEvent 默认是 UPDATING; requestFor 后可换为 FORCE_DELETING
it('round-trips the request event through requestFor/getRequestEvent', function (): void {
    $product = new Product;

    expect($product->getRequestEvent())->toBe(ApprovableEvent::UPDATING);

    $product->requestFor(ApprovableEvent::FORCE_DELETING);

    expect($product->getRequestEvent())->toBe(ApprovableEvent::FORCE_DELETING);
});

// 验证: CREATING 时 old_values 取自 Product 覆写的 custom_old, new_values 合并属性与 custom_new
it('builds CREATING attributes from raw attributes and merges custom values', function (): void {
    $product = new Product(['name' => 'fresh']);
    $product->requestFor(ApprovableEvent::CREATING);

    $payload = $product->toApprovalAttributes();

    expect($payload['event'])->toBe(ApprovableEvent::CREATING)
        ->and($payload['old_values'])->toBe(['custom_old' => 'before'])
        ->and($payload['new_values'])->toMatchArray([
            'name' => 'fresh',
            'custom_new' => 'after',
        ]);
});

// 验证: UPDATING 只携带 dirty 字段; 预期: old_values=原 name, new_values=新 name
it('builds UPDATING attributes from dirty fields only', function (): void {
    $product = Product::newModel();
    $original = $product->name;

    $product->name = 'renamed';
    $product->requestFor(ApprovableEvent::UPDATING);

    $payload = $product->toApprovalAttributes();

    expect($payload['event'])->toBe(ApprovableEvent::UPDATING)
        ->and($payload['old_values'])->toBe(['name' => $original])
        ->and($payload['new_values'])->toBe(['name' => 'renamed']);
});

// 验证: TRASHING 仅操纵 deleted_at 列; old=null, new=时间字符串
it('builds TRASHING attributes with deleted_at toggling', function (): void {
    $product = new Product;
    $product->requestFor(ApprovableEvent::TRASHING);

    $payload = $product->toApprovalAttributes();
    $col = $product->getDeletedAtColumn();

    expect($payload['event'])->toBe(ApprovableEvent::TRASHING)
        ->and($payload['old_values'])->toBe([$col => null])
        ->and($payload['new_values'][$col])->toBeString();
});

// 验证: RESTORING 与 TRASHING 对称, old=时间字符串, new=[deleted_at=>null]
it('builds RESTORING attributes with deleted_at being cleared', function (): void {
    $product = Product::newModel();
    $product->{$product->getDeletedAtColumn()} = now();
    $product->syncOriginal();
    $product->requestFor(ApprovableEvent::RESTORING);

    $payload = $product->toApprovalAttributes();
    $col = $product->getDeletedAtColumn();

    expect($payload['event'])->toBe(ApprovableEvent::RESTORING)
        ->and($payload['old_values'][$col])->toBeString()
        ->and($payload['new_values'])->toBe([$col => null]);
});

// 验证: FORCE_DELETING 时 old=完整原始属性, new=空对象 (代表彻底清除)
it('builds FORCE_DELETING attributes from raw original', function (): void {
    $product = Product::newModel();
    $product->requestFor(ApprovableEvent::FORCE_DELETING);

    $payload = $product->toApprovalAttributes();

    expect($payload['event'])->toBe(ApprovableEvent::FORCE_DELETING)
        ->and($payload['old_values'])->toMatchArray(['name' => $product->name])
        ->and($payload['new_values'])->toEqual((object) []);
});

// 验证: toApproval 返回的 Approval 已绑定 approvable morph 与正确 event/new_values
it('toApproval returns an Approval bound to this morph', function (): void {
    $product = Product::newModel();
    $product->name = 'changed';
    $product->requestFor(ApprovableEvent::UPDATING);

    $approval = $product->toApproval();

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval->event)->toBe(ApprovableEvent::UPDATING)
        ->and($approval->approvable_type)->toBe($product->getMorphClass())
        ->and($approval->approvable_id)->toEqual($product->getKey())
        ->and($approval->new_values)->toBe(['name' => 'changed']);
});
