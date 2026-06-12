<?php

/**
 * 测试目的: 覆盖 src/Models/Approval 模型 (审批快照: 记录被审对象与新旧值)
 * 覆盖范围: 字段类型 cast、task/approvable morph 关系、approvable_title 拼装与 fallback、isEffected/isRolledBack 标记、whereAffectable/whereEffected/whereRolledBack/whereNotRolledBack 四个 scope
 */

use App\Models\Article;
use App\Models\User;
use PHPTools\Approval\Enums\ApprovableEvent;
use PHPTools\Approval\Models\Approval;
use PHPTools\Approval\Models\ApprovalTask;

// 验证: approval_task_id/order_number 为 int, event 为枚举, effected_at/rolled_back_at 默认 null
it('casts attributes to the expected types', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    /** @var Approval $approval */
    $approval = $task->approvals->first();

    expect($approval->approval_task_id)->toBeInt()
        ->and($approval->order_number)->toBeInt()
        ->and($approval->event)->toBeInstanceOf(ApprovableEvent::class)
        ->and($approval->effected_at)->toBeNull()
        ->and($approval->rolled_back_at)->toBeNull();
});

// 验证: task() 反向取出 ApprovalTask, approvable() 通过 morph 取出 Article
it('resolves task and approvable relations', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    /** @var Approval $approval */
    $approval = $task->approvals->first();

    expect($approval->task)->toBeInstanceOf(ApprovalTask::class)
        ->and($approval->task->getKey())->toBe($task->getKey())
        ->and($approval->approvable)->toBeInstanceOf(Article::class);
});

// 验证: approvable_title 格式为 "<label> #<id>"; 预期: 形如 "article #123"
it('builds an approvable title with model label and id', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    /** @var Approval $approval */
    $approval = $task->approvals->first();

    expect($approval->approvable_title)->toMatch('/^article #\d+$/');
});

// 验证: CREATING 这种尚未持久化的对象 id 为空时, title 返回 "<label> #new"
it('falls back to "new" in the approvable title when id is empty', function (): void {
    $approval = new Approval([
        'approvable_type' => (new Article)->getMorphClass(),
        'approvable_id' => null,
    ]);

    expect($approval->approvable_title)->toBe('article #new');
});

// 验证: markAsEffected 设置 effected_at 并使 isEffected 返回 true
it('flags effected approvals via isEffected and markAsEffected', function (): void {
    $approval = new Approval;

    expect($approval->isEffected())->toBeFalse();

    $approval->markAsEffected();

    expect($approval->isEffected())->toBeTrue()
        ->and($approval->effected_at)->not->toBeNull();
});

// 验证: markAsRolledBack 设置 rolled_back_at 并使 isRolledBack 返回 true
it('flags rolled back approvals via isRolledBack and markAsRolledBack', function (): void {
    $approval = new Approval;

    expect($approval->isRolledBack())->toBeFalse();

    $approval->markAsRolledBack();

    expect($approval->isRolledBack())->toBeTrue()
        ->and($approval->rolled_back_at)->not->toBeNull();
});

// 验证: whereAffectable(未应用) 与 whereEffected(已应用) 互斥; 预期: markAsEffected 后两者计数翻转
it('filters approvals via whereAffectable and whereEffected scopes', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    /** @var Approval $approval */
    $approval = $task->approvals->first();

    expect(Approval::query()->whereAffectable()->count())->toBe(1)
        ->and(Approval::query()->whereEffected()->count())->toBe(0);

    $approval->markAsEffected()->save();

    expect(Approval::query()->whereAffectable()->count())->toBe(0)
        ->and(Approval::query()->whereEffected()->count())->toBe(1);
});

// 验证: whereNotRolledBack 与 whereRolledBack 互斥; 预期: markAsRolledBack 后两者计数翻转
it('filters approvals via whereRolledBack and whereNotRolledBack scopes', function (): void {
    [$applicant, $approver] = User::newModels(2);
    $task = buildTaskFor($applicant, [$approver]);

    /** @var Approval $approval */
    $approval = $task->approvals->first();

    expect(Approval::query()->whereNotRolledBack()->count())->toBe(1)
        ->and(Approval::query()->whereRolledBack()->count())->toBe(0);

    $approval->markAsRolledBack()->save();

    expect(Approval::query()->whereNotRolledBack()->count())->toBe(0)
        ->and(Approval::query()->whereRolledBack()->count())->toBe(1);
});
