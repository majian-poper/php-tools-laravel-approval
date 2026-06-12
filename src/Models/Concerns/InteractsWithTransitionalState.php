<?php

namespace PHPTools\Approval\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use PHPTools\Approval\Enums;

/**
 * @property \DateTimeInterface | null $rolled_back_at
 *
 * @method static Builder | static whereApproving()
 * @method static Builder | static whereRollingBack()
 * @method static Builder | static whereRolledBack()
 */
trait InteractsWithTransitionalState
{
    use InteractsWithState {
        markAsPending as protected baseMarkAsPending;
        markAsApproved as protected baseMarkAsApproved;
        markAsRejected as protected baseMarkAsRejected;
    }

    public function scopeWhereApproving(Builder $query): Builder
    {
        return $this->scopeWhereStatus($query, Enums\ApprovalStatus::APPROVING);
    }

    public function scopeWhereRollingBack(Builder $query): Builder
    {
        return $this->scopeWhereStatus($query, Enums\ApprovalStatus::ROLLING_BACK);
    }

    public function scopeWhereRolledBack(Builder $query): Builder
    {
        return $this->scopeWhereStatus($query, Enums\ApprovalStatus::ROLLED_BACK);
    }

    public function getRolledBackAt(): ?\DateTimeInterface
    {
        return $this->rolled_back_at;
    }

    public function isApproving(): bool
    {
        return $this->status === Enums\ApprovalStatus::APPROVING;
    }

    public function isRollingBack(): bool
    {
        return $this->status === Enums\ApprovalStatus::ROLLING_BACK;
    }

    public function isRolledBack(): bool
    {
        return $this->status === Enums\ApprovalStatus::ROLLED_BACK;
    }

    public function markAsPending(): self
    {
        return $this->baseMarkAsPending()
            ->markRolledBackAtAs(null);
    }

    public function markAsApproving(): self
    {
        return $this->forceFill(['status' => Enums\ApprovalStatus::APPROVING])
            ->markApprovedAtAs(null)
            ->markRolledBackAtAs(null);
    }

    public function markAsApproved(): self
    {
        return $this->baseMarkAsApproved()
            ->markRolledBackAtAs(null);
    }

    public function markAsRejected(): self
    {
        return $this->baseMarkAsRejected()
            ->markRolledBackAtAs(null);
    }

    public function markAsRollingBack(): self
    {
        return $this->forceFill(['status' => Enums\ApprovalStatus::ROLLING_BACK])
            ->markRolledBackAtAs(null);
    }

    public function markAsRolledBack(): self
    {
        return $this->forceFill(['status' => Enums\ApprovalStatus::ROLLED_BACK])
            ->markRolledBackAtAs($this->freshTimestamp());
    }

    protected function markRolledBackAtAs(?\DateTimeInterface $rolledBackAt): self
    {
        return $this->forceFill(['rolled_back_at' => $rolledBackAt]);
    }
}
