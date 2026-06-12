<?php

namespace PHPTools\Approval\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use PHPTools\Approval\Contracts;
use PHPTools\Approval\Enums;

/**
 * @property int $approval_task_id
 * @property int $order_number
 * @property string $approvable_type
 * @property string $approvable_id
 * @property string $approvable_unique_key
 * @property string $created_unique_key
 * @property Enums\ApprovableEvent $event
 * @property array<string, mixed> $old_values
 * @property array<string, mixed> $new_values
 * @property \Carbon\CarbonImmutable | null $effected_at
 * @property \Carbon\CarbonImmutable | null $rolled_back_at
 *
 * @property-read string $approvable_title
 * @property-read ApprovalTask $task
 * @property-read Contracts\Approvable $approvable
 *
 * @method static Builder | static whereAffectable()
 * @method static Builder | static whereEffected()
 * @method static Builder | static whereNotRolledBack()
 * @method static Builder | static whereRolledBack()
 */
class Approval extends Model
{
    protected $fillable = [
        'approval_task_id',
        'order_number',
        'approvable_type',
        'approvable_id',
        'approvable_unique_key',
        'created_unique_key',
        'event',
        'old_values',
        'new_values',
        'effected_at',
        'rolled_back_at',
    ];

    protected $casts = [
        'approval_task_id' => 'int',
        'order_number' => 'int',
        'approvable_type' => 'string',
        'approvable_id' => 'string',
        'approvable_unique_key' => 'string',
        'created_unique_key' => 'string',
        'event' => Enums\ApprovableEvent::class,
        'old_values' => 'json:unicode',
        'new_values' => 'json:unicode',
        'effected_at' => 'immutable_datetime',
        'rolled_back_at' => 'immutable_datetime',
    ];

    protected static array $typeLabelCache = [];

    public function getApprovableTitleAttribute(): string
    {
        $type = $this->approvable_type;

        $label = static::$typeLabelCache[$type] ??= app(Model::getActualClassNameForMorph($type))->getLabel();

        return \sprintf('%s #%s', $label, $this->approvable_id ?: 'new');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(config('approval.implementations.approval_task', ApprovalTask::class), 'approval_task_id');
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo('approvable')->withTrashed();
    }

    public function scopeWhereAffectable(Builder $query): Builder
    {
        return $query->whereNull('effected_at');
    }

    public function scopeWhereEffected(Builder $query): Builder
    {
        return $query->whereNotNull('effected_at');
    }

    public function scopeWhereNotRolledBack(Builder $query): Builder
    {
        return $query->whereNull('rolled_back_at');
    }

    public function scopeWhereRolledBack(Builder $query): Builder
    {
        return $query->whereNotNull('rolled_back_at');
    }

    public function isEffected(): bool
    {
        return filled($this->effected_at);
    }

    public function isRolledBack(): bool
    {
        return filled($this->rolled_back_at);
    }

    public function markAsEffected(): self
    {
        $this->effected_at = $this->freshTimestamp();

        return $this;
    }

    public function markAsRolledBack(): self
    {
        $this->rolled_back_at = $this->freshTimestamp();

        return $this;
    }
}
