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
 * @method static Builder | static whereApprover(Contracts\Approver & Model $approver)
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

    public function scopeWhereApprover(Builder $query, Contracts\Approver $approver): void
    {
        $query->whereRelation(
            'steps',
            static fn(Builder $query): Builder => $query->whereMorphedTo('approver', $approver)
        );
    }

    public function scopeWhereApprovers(Builder $query, Contracts\Approver ...$approvers): void
    {
        $query->where(
            static function (Builder $query) use ($approvers): void {
                foreach ($approvers as $approver) {
                    $query->orWhere(static fn(Builder $query) => $query->whereApprover($approver));
                }
            }
        );
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function canBeChangedStatus(): bool
    {
        return ! $this->isExpired() && $this->isPending();
    }

    public function canBeChangedStatusBy(Authenticatable $user): bool
    {
        return $this->canBeChangedStatus() && $this->affectableSteps($user)->isNotEmpty();
    }

    public function canBeRolledBack(): bool
    {
        return $this->isApproved() && ! $this->isRolledBack();
    }

    public function canBeRolledBackBy(Authenticatable $user): bool
    {
        return $this->canBeRolledBack() && $user instanceof Contracts\Approver && $user->canRollBack();
    }

    public function approve(string $comment = ''): bool
    {
        throw_if($this->isExpired(), Exceptions\ApprovalTaskExpiredException::class, $this);

        $this->expectStatus(Enums\ApprovalStatus::PENDING);

        if (! $this->approveSteps($comment)) {
            return false;
        }

        $saved = $this->markAsApproving()->save();

        if ($saved) {
            event(new Events\ApprovalTaskApproved($this));

            Jobs\ApproveTaskJob::dispatch($this, config('approval.chunk_size', 100));
        }

        return $saved;
    }

    public function reject(string $comment = ''): bool
    {
        throw_if($this->isExpired(), Exceptions\ApprovalTaskExpiredException::class, $this);

        $this->expectStatus(Enums\ApprovalStatus::PENDING);

        if (! $this->rejectSteps($comment)) {
            return false;
        }

        $saved = $this->markAsRejected()->save();

        if ($saved) {
            event(new Events\ApprovalTaskRejected($this));
        }

        return $saved;
    }

    public function rollBack(): bool
    {
        throw_if($this->isRolledBack(), Exceptions\RollBackFailedException::class, $this);

        $this->expectStatus(Enums\ApprovalStatus::APPROVED);

        $saved = $this->markAsRollingBack()->save();

        if ($saved) {
            event(new Events\ApprovalTaskRolledBack($this));

            Jobs\RollBackTaskJob::dispatch($this, config('approval.chunk_size', 100));
        }

        return $saved;
    }

    protected function affectableSteps(Authenticatable $user): Collection
    {
        return $this->steps->filter(
            static fn(ApprovalStep $step): bool => $step->isPending() && $step->contains($user)
        );
    }

    protected function approveSteps(string $comment = ''): bool
    {
        $user = ApprovalFacade::resolveUser();

        /** @var ApprovalStep $step */
        foreach ($this->affectableSteps($user) as $step) {
            try {
                $step->approveBy($user, $comment);
            } catch (\Exception $e) {
                //
            }
        }

        return $this->isStepsApproved();
    }

    protected function rejectSteps(string $comment = ''): bool
    {
        $user = ApprovalFacade::resolveUser();

        /** @var ApprovalStep $step */
        foreach ($this->affectableSteps($user) as $step) {
            try {
                $step->rejectBy($user, $comment);
            } catch (\Exception $e) {
                //
            }
        }

        return $this->isStepsRejected();
    }

    protected function isStepsApproved(): bool
    {
        if ($this->steps->isEmpty()) {
            return true;
        }

        return match ($this->flow_type) {
            Enums\ApprovalFlowType::EVERY => $this->steps->every->isApproved(),
            Enums\ApprovalFlowType::ANY => $this->steps->filter->isApproved()->isNotEmpty(),
        };
    }

    protected function isStepsRejected(): bool
    {
        if ($this->steps->isEmpty()) {
            return true;
        }

        return match ($this->flow_type) {
            Enums\ApprovalFlowType::EVERY => $this->steps->filter->isRejected()->isNotEmpty(),
            Enums\ApprovalFlowType::ANY => $this->steps->every->isRejected(),
        };
    }
}
