<?php

namespace PHPTools\Approval\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use PHPTools\Approval\Enums;
use PHPTools\Approval\Exceptions;

/**
 * @property Enums\ApprovalStatus $status
 * @property \DateTimeInterface | null $approved_at
 *
 * @method static Builder | static whereStatus(Enums\ApprovalStatus ...$statuses)
 * @method static Builder | static wherePending()
 * @method static Builder | static whereApproved()
 * @method static Builder | static whereRejected()
 */
trait InteractsWithState
{
    public function scopeWhereStatus(Builder $query, Enums\ApprovalStatus ...$statuses): Builder
    {
        return $query->whereIn('status', $statuses);
    }

    public function scopeWherePending(Builder $query): Builder
    {
        return $this->scopeWhereStatus($query, Enums\ApprovalStatus::PENDING);
    }

    public function scopeWhereApproved(Builder $query): Builder
    {
        return $this->scopeWhereStatus($query, Enums\ApprovalStatus::APPROVED);
    }

    public function scopeWhereRejected(Builder $query): Builder
    {
        return $this->scopeWhereStatus($query, Enums\ApprovalStatus::REJECTED);
    }

    public function getStatus(): Enums\ApprovalStatus
    {
        return $this->status;
    }

    public function getApprovedAt(): ?\DateTimeInterface
    {
        return $this->approved_at;
    }

    public function isPending(): bool
    {
        return $this->status === Enums\ApprovalStatus::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === Enums\ApprovalStatus::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === Enums\ApprovalStatus::REJECTED;
    }

    public function expectStatus(Enums\ApprovalStatus $status): void
    {
        throw_unless(
            $this->getStatus() === $status,
            Exceptions\UnexpectedApprovalStatusException::class,
            $status,
            $this
        );
    }

    public function markAsPending(): self
    {
        return $this->forceFill(['status' => Enums\ApprovalStatus::PENDING])
            ->markApprovedAtAs(null);
    }

    public function markAsApproved(): self
    {
        return $this->forceFill(['status' => Enums\ApprovalStatus::APPROVED])
            ->markApprovedAtAs($this->freshTimestamp());
    }

    public function markAsRejected(): self
    {
        return $this->forceFill(['status' => Enums\ApprovalStatus::REJECTED])
            ->markApprovedAtAs($this->freshTimestamp());
    }

    protected function markApprovedAtAs(?\DateTimeInterface $approvedAt): self
    {
        return $this->forceFill(['approved_at' => $approvedAt]);
    }
}
