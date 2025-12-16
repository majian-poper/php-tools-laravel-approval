<?php

namespace EnaTools\Approval\Models;

use EnaTools\Approval\Contracts;
use EnaTools\Approval\Enums;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function setTitle(string $title): void
    {
        $this->name = $title;
    }

    public function getTitle(): string
    {
        return $this->name;
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
