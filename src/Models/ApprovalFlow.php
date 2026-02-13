<?php

namespace PHPTools\Approval\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PHPTools\Approval\Contracts;
use PHPTools\Approval\Enums;

/**
 * @property string $name
 * @property string $approvable_type
 * @property Enums\ApprovalFlowType $flow_type
 * @property int $expiration
 *
 * @property \Illuminate\Database\Eloquent\Collection<int, ApprovalFlowStep> $steps
 */
class ApprovalFlow extends Model implements Contracts\Flow
{
    protected $fillable = [
        'name',
        'approvable_type',
        'flow_type',
        'expiration',
    ];

    protected $casts = [
        'name' => 'string',
        'approvable_type' => 'string',
        'flow_type' => Enums\ApprovalFlowType::class,
        'expiration' => 'int',
    ];

    protected string $title = '';

    protected string $description = '';

    protected static function booted()
    {
        static::deleting(static fn(self $model) => $model->steps()->delete());
    }

    public function steps(): HasMany
    {
        return $this
            ->hasMany(config('approval.implementations.approval_flow_step', ApprovalFlowStep::class))
            ->orderBy('order_number');
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title ?: $this->name;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getType(): Enums\ApprovalFlowType
    {
        return $this->flow_type;
    }

    public function getExpiresAt(): \DateTimeInterface
    {
        return $this->freshTimestamp()->addSeconds($this->expiration);
    }

    public function getApprovers(): array
    {
        return $this
            ->loadMissing(['steps.approver'])
            ->steps
            ->pluck('approver')
            ->unique('approver_title')
            ->all();
    }
}
