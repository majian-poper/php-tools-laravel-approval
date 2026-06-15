<?php

namespace PHPTools\Approval\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use PHPTools\Approval\Contracts;
use PHPTools\Approval\Enums;
use PHPTools\Approval\Events;
use PHPTools\Approval\Exceptions;
use PHPTools\Approval\Facades\ApprovalFacade;
use PHPTools\Approval\Jobs;

/**
 * @property string $title
 * @property string $description
 * @property string $user_type
 * @property int $user_id
 * @property Enums\ApprovalFlowType $flow_type
 * @property Enums\ApprovalStatus $status
 * @property \Carbon\CarbonImmutable $expires_at
 * @property \Carbon\CarbonImmutable | null $approved_at
 * @property \Carbon\CarbonImmutable | null $rolled_back_at
 *
 * @property-read Authenticatable & Model $user
 * @property-read \Illuminate\Database\Eloquent\Collection<Approval> $approvals
 * @property-read \Illuminate\Database\Eloquent\Collection<ApprovalStep> $steps
 *
 * @method static Builder | static whereApprovers(Contracts\Approver & Model ...$approvers)
 */
class ApprovalTask extends Model implements Contracts\HasState
{
    use Concerns\InteractsWithTransitionalState;

    public function getFillable(): array
    {
        static $fillables;

        if (! isset($fillables)) {
            $fillableResolvers = collect(config('approval.column_resolvers', []))
                ->filter(static fn(string $resolver): bool => \is_subclass_of($resolver, Contracts\ColumnResolver::class))
                ->map(static fn(string $resolver): string => \call_user_func([$resolver, 'name']))
                ->values();

            $fillables = [
                'title',
                'description',
                'user_type',
                'user_id',
                'flow_type',
                'status',
                'expires_at',
                'approved_at',
                'rolled_back_at',
                ...$fillableResolvers->all(),
            ];
        }

        return $fillables;
    }

    public function casts(): array
    {
        static $casts;

        if (! isset($casts)) {
            $attributeCasts = collect(config('approval.column_resolvers', []))
                ->filter(static fn(string $resolver): bool => \is_subclass_of($resolver, Contracts\ColumnResolver::class))
                ->mapWithKeys(
                    static fn(string $resolver): array => [
                        \call_user_func([$resolver, 'name']) => \call_user_func([$resolver, 'attributeCast'])
                    ]
                );

            $casts = [
                'title' => 'string',
                'description' => 'string',
                'user_type' => 'string',
                'user_id' => 'int',
                'flow_type' => Enums\ApprovalFlowType::class,
                'status' => Enums\ApprovalStatus::class,
                'expires_at' => 'immutable_datetime',
                'approved_at' => 'immutable_datetime',
                'rolled_back_at' => 'immutable_datetime',
                ...$attributeCasts->all(),
            ];
        }

        return $casts;
    }

    public function user(): MorphTo
    {
        return $this->morphTo('user');
    }

    public function steps(): HasMany
    {
        return $this
            ->hasMany(config('approval.implementations.approval_step', ApprovalStep::class))
            ->orderBy('order_number');
    }

    public function approvals(): HasMany
    {
        return $this
            ->hasMany(config('approval.implementations.approval', Approval::class))
            ->orderBy('order_number');
    }

    public function scopeWhereApprovers(Builder $query, Contracts\Approver ...$approvers): Builder
    {
        return $query->whereRelation(
            'steps',
            static fn(Builder $query): Builder => $query->whereMorphedTo('approver', $approvers)
        );
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function canChangeStatus(): bool
    {
        return ! $this->isExpired() && $this->isPending();
    }

    public function canStatusBeChangedBy(Authenticatable $user): bool
    {
        return $this->canChangeStatus() && $this->firstAffectableStepsFor($user)->isNotEmpty();
    }

    public function canBeRolledBack(): bool
    {
        return $this->isApproved() && ! $this->isRolledBack();
    }

    public function canBeRolledBackBy(Authenticatable $user): bool
    {
        return $this->canBeRolledBack() && $user instanceof Contracts\Approver && $user->canRollBack();
    }

    public function approve(string $comment = '', ?Authenticatable $user = null): bool
    {
        $user = $user ?? ApprovalFacade::resolveUser();

        throw_unless(
            $this->canStatusBeChangedBy($user),
            fn() => new Exceptions\ChangeStatusFailedException($this, Enums\ApprovalStatus::APPROVED, $user)
        );

        $this->firstAffectableStepsFor($user)->each->approveBy($user, $comment);

        // 刷新 steps 关系以确保 isStepsApproved 获取到最新状态
        $saved = $this->load('steps')->isStepsApproved()
            ? $this->getConnection()->transaction(
                fn(): bool => $this->newQuery()->lockForUpdate()->find($this->getKey())->markAsApproving()->save()
            )
            : false;

        if ($saved) {
            event(new Events\ApprovalTaskApproved($this));

            Jobs\ApproveTaskJob::dispatch($this, config('approval.chunk_size', 100));
        }

        return $saved;
    }

    public function reject(string $comment = '', ?Authenticatable $user = null): bool
    {
        $user = $user ?? ApprovalFacade::resolveUser();

        throw_unless(
            $this->canStatusBeChangedBy($user),
            fn() => new Exceptions\ChangeStatusFailedException($this, Enums\ApprovalStatus::REJECTED, $user)
        );

        $this->firstAffectableStepsFor($user)->each->rejectBy($user, $comment);

        // 刷新 steps 关系以确保 isStepsRejected 获取到最新状态
        $saved = $this->load('steps')->isStepsRejected() ? $this->markAsRejected()->save() : false;

        if ($saved) {
            event(new Events\ApprovalTaskRejected($this));
        }

        return $saved;
    }

    public function rollBack(?Authenticatable $user = null): bool
    {
        $user = $user ?? ApprovalFacade::resolveUser();

        throw_unless($this->canBeRolledBackBy($user), fn() => new Exceptions\RollBackFailedException($this, $user));

        $saved = $this->markAsRollingBack()->save();

        if ($saved) {
            event(new Events\ApprovalTaskRolledBack($this));

            Jobs\RollBackTaskJob::dispatch($this, config('approval.chunk_size', 100));
        }

        return $saved;
    }

    /**
     * 获取当前用户可处理的第一个审批步骤层级
     *
     * @param Authenticatable $user
     *
     * @return Collection<int, ApprovalStep>
     */
    protected function firstAffectableStepsFor(Authenticatable $user): Collection
    {
        return $this->steps
            // 按照 order_number 分组, 同一 order_number 的 Step 是同层级的审批
            ->groupBy('order_number')
            // 该层级中每一个 Step 都是 Pending 状态, 表示该层级 Step 正在等待审批
            ->filter->every(static fn(ApprovalStep $step): bool => $step->isPending())
            // 每个层级只保留可被当前用户处理的 Step
            ->map->filter(static fn(ApprovalStep $step): bool => $step->contains($user))
            ->filter->isNotEmpty()
            ->first() ?? collect();
    }

    protected function isStepsApproved(): bool
    {
        // 没有审批步骤时, 视为已批准, 等价于跳过审批步骤的场景
        // 但实际场景中不会触发此处的极端情况的判断逻辑
        if ($this->steps->isEmpty()) {
            return true;
        }

        $method = match ($this->flow_type) {
            // EVERY 流程类型下, 只要有一个审批步骤未被批准, 就视为审批未被批准
            Enums\ApprovalFlowType::EVERY => 'every',
            // ANY 流程类型下, 只要有一个审批步骤被批准, 就视为审批被批准
            Enums\ApprovalFlowType::ANY => 'some',
        };

        return $this->steps->groupBy('order_number')->{$method}(
            static fn(Collection $steps): bool => $steps->filter->isApproved()->isNotEmpty()
        );
    }

    protected function isStepsRejected(): bool
    {
        // 没有审批步骤时, 视为未被拒绝, 等价于跳过审批步骤的场景
        // 但实际场景中不会触发此处的极端情况的判断逻辑
        if ($this->steps->isEmpty()) {
            return false;
        }

        $method = match ($this->flow_type) {
            // EVERY 流程类型下, 只要有一个审批步骤被拒绝, 就视为审批被拒绝
            Enums\ApprovalFlowType::EVERY => 'some',
            // ANY 流程类型下, 所有审批步骤都被拒绝, 才视为审批被拒绝
            Enums\ApprovalFlowType::ANY => 'every',
        };

        return $this->steps->groupBy('order_number')->{$method}(
            static fn(Collection $steps): bool => $steps->filter->isRejected()->isNotEmpty()
        );
    }
}
