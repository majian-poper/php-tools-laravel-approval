<?php

/**
 * 测试目的: 覆盖 src/Models/ApprovalFlow (持久化审批模板) 的字段、属性方法、steps 关系与级联删除
 * 覆盖范围: cast 类型、title/description fallback、expiration 转 expiresAt、步骤排序与按 level 过滤、按 order_number 分组的 getApprovers、删除时 step 级联清理
 */

use App\Models\Article;
use App\Models\User;
use PHPTools\Approval\Enums\ApprovalFlowType;
use PHPTools\Approval\Models\ApprovalFlow;
use PHPTools\Approval\Models\ApprovalFlowStep;

function makeApprovalFlow(string $approvableType = 'article', int $expiration = 3600, ApprovalFlowType $type = ApprovalFlowType::EVERY): ApprovalFlow
{
    return ApprovalFlow::query()->create([
        'name' => 'Test flow',
        'approvable_type' => $approvableType,
        'expiration' => $expiration,
        'flow_type' => $type->value,
    ]);
}

// 验证: name/approvable_type 为 string, flow_type 为枚举, expiration 为 int
it('casts attributes to the expected types', function (): void {
    $flow = makeApprovalFlow();

    expect($flow->name)->toBeString()
        ->and($flow->approvable_type)->toBeString()
        ->and($flow->flow_type)->toBeInstanceOf(ApprovalFlowType::class)
        ->and($flow->expiration)->toBeInt();
});

// 验证: 未设置 title 时 getTitle 返回 name 字段, getDescription 返回空串
it('falls back to name when no explicit title is set', function (): void {
    $flow = makeApprovalFlow();

    expect($flow->getTitle())->toBe('Test flow')
        ->and($flow->getDescription())->toBe('');
});

// 验证: setTitle/setDescription fluent setter; 预期: 后续 getter 返回所设值, 不再回退 name
it('returns title and description set via fluent setters', function (): void {
    $flow = makeApprovalFlow();

    $flow->setTitle('Custom Title')->setDescription('Custom Desc');

    expect($flow->getTitle())->toBe('Custom Title')
        ->and($flow->getDescription())->toBe('Custom Desc');
});

// 验证: getExpiresAt 在 freshTimestamp 上加 expiration 秒; 预期: 差值刚好为传入的 1800
it('exposes flow type and expiration in seconds added to current timestamp', function (): void {
    $flow = makeApprovalFlow(expiration: 1800, type: ApprovalFlowType::ANY);

    $expiresAt = $flow->getExpiresAt();
    $now = $flow->freshTimestamp();

    expect($flow->getType())->toBe(ApprovalFlowType::ANY)
        ->and($expiresAt->getTimestamp() - $now->getTimestamp())->toBe(1800);
});

// 验证: steps() 关系按 order_number 升序后再按 id 升序; 预期: [1,1,2]
it('orders steps by order_number then id', function (): void {
    $flow = makeApprovalFlow();
    [$a, $b, $c] = User::newModels(3);

    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->getKey(),
        'order_number' => 2,
        'approver_type' => $a->getMorphClass(),
        'approver_id' => $a->getKey(),
    ]);

    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->getKey(),
        'order_number' => 1,
        'approver_type' => $b->getMorphClass(),
        'approver_id' => $b->getKey(),
    ]);

    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->getKey(),
        'order_number' => 1,
        'approver_type' => $c->getMorphClass(),
        'approver_id' => $c->getKey(),
    ]);

    $orderNumbers = $flow->steps()->get()->pluck('order_number')->all();

    expect($orderNumbers)->toBe([1, 1, 2]);
});

// 验证: steps_one() 到 steps_five() 按 level 过滤 order_number; 预期: level 3/4/5 为空时计数为 0
it('filters steps by step level via steps_one, steps_two, etc.', function (): void {
    $flow = makeApprovalFlow();
    [$a, $b] = User::newModels(2);

    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->getKey(),
        'order_number' => 1,
        'approver_type' => $a->getMorphClass(),
        'approver_id' => $a->getKey(),
    ]);

    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->getKey(),
        'order_number' => 2,
        'approver_type' => $b->getMorphClass(),
        'approver_id' => $b->getKey(),
    ]);

    expect($flow->steps_one()->count())->toBe(1)
        ->and($flow->steps_two()->count())->toBe(1)
        ->and($flow->steps_three()->count())->toBe(0)
        ->and($flow->steps_four()->count())->toBe(0)
        ->and($flow->steps_five()->count())->toBe(0);
});

// 验证: getApprovers() 按 order_number 分组成 [1=>[a,b], 2=>[c]]; 这是 group step 的关键数据
it('groups approvers by order_number in getApprovers', function (): void {
    $flow = makeApprovalFlow();
    [$a, $b, $c] = User::newModels(3);

    foreach ([
        ['order_number' => 1, 'approver' => $a],
        ['order_number' => 1, 'approver' => $b],
        ['order_number' => 2, 'approver' => $c],
    ] as $stepValues) {
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->getKey(),
            'order_number' => $stepValues['order_number'],
            'approver_type' => $stepValues['approver']->getMorphClass(),
            'approver_id' => $stepValues['approver']->getKey(),
        ]);
    }

    $approvers = $flow->getApprovers();

    expect($approvers)->toHaveCount(2)
        ->and($approvers[1])->toHaveCount(2)
        ->and($approvers[2])->toHaveCount(1)
        ->and($approvers[2][0]->getKey())->toBe($c->getKey());
});

// 验证: flow 删除会级联清空 ApprovalFlowStep, 避免孤儿记录
it('cascades step deletion when the flow is deleted', function (): void {
    $flow = makeApprovalFlow();
    [$a] = User::newModels(1);

    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->getKey(),
        'order_number' => 1,
        'approver_type' => $a->getMorphClass(),
        'approver_id' => $a->getKey(),
    ]);

    expect(ApprovalFlowStep::query()->count())->toBe(1);

    $flow->delete();

    expect(ApprovalFlowStep::query()->count())->toBe(0);
});

// 验证: approvable_type 接受 Article 的 morph alias, 与 ApprovalManager 解析路径打通
it('is also resolvable through Article morph class', function (): void {
    $article = Article::newModel();

    $flow = makeApprovalFlow(approvableType: $article->getMorphClass());

    expect($flow->approvable_type)->toBe($article->getMorphClass());
});
