<?php

/**
 * 测试目的: 覆盖 src/SimpleFlow 这个内存型 ApprovalFlowContract 实现
 * 覆盖范围: 构造参数 getter 回读、fluent setter 链式调用、approvers iterable 透传
 */

use PHPTools\Approval\Enums\ApprovalFlowType;
use PHPTools\Approval\SimpleFlow;

// 验证: 构造时所有字段都能从 getter 原样取回; 预期: 包含 expiresAt 的 Y-m-d 格式与空 approvers
it('returns constructor values from getters', function (): void {
    $flow = new SimpleFlow(
        title: 'Approve article change',
        description: 'Body of the request',
        type: ApprovalFlowType::EVERY,
        expiresAt: new DateTimeImmutable('2099-01-01'),
        approvers: [],
    );

    expect($flow->getTitle())->toBe('Approve article change')
        ->and($flow->getDescription())->toBe('Body of the request')
        ->and($flow->getType())->toBe(ApprovalFlowType::EVERY)
        ->and($flow->getExpiresAt()->format('Y-m-d'))->toBe('2099-01-01')
        ->and($flow->getApprovers())->toBe([]);
});

// 验证: setTitle/setDescription 链式返回 $this; 预期: 修改后属性立刻可读取
it('mutates title and description through fluent setters', function (): void {
    $flow = new SimpleFlow(
        title: 'Old',
        description: 'Old description',
        type: ApprovalFlowType::ANY,
        expiresAt: new DateTimeImmutable,
        approvers: ['approver-stub'],
    );

    $result = $flow->setTitle('New title')->setDescription('New description');

    expect($result)->toBe($flow)
        ->and($flow->getTitle())->toBe('New title')
        ->and($flow->getDescription())->toBe('New description');
});

// 验证: 传入 Generator 时不会被强转为 array; 预期: foreach 迭代仍能拿到原始顺序的两个元素
it('returns the same approvers iterable passed in', function (): void {
    $approvers = (function (): Generator {
        yield 'one';
        yield 'two';
    })();

    $flow = new SimpleFlow('T', 'D', ApprovalFlowType::EVERY, new DateTimeImmutable, $approvers);

    $values = [];
    foreach ($flow->getApprovers() as $approver) {
        $values[] = $approver;
    }

    expect($values)->toBe(['one', 'two']);
});
