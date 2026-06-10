<?php

namespace PHPTools\Approval\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $approval_flow_id
 * @property int $order_number
 * @property int $approver_id
 * @property string $approver_type
 *
 * @property-read ApprovalFlow $flow
 * @property-read \PHPTools\Approval\Contracts\Approver & Model $approver
 */
class ApprovalFlowStep extends Model
{
    protected $fillable = [
        'approval_flow_id',
        'order_number',
        'approver_id',
        'approver_type',
    ];

    protected $casts = [
        'approval_flow_id' => 'int',
        'order_number' => 'int',
        'approver_id' => 'int',
        'approver_type' => 'string',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(
            config('approval.implementations.approval_flow', ApprovalFlow::class),
            'approval_flow_id'
        );
    }

    public function approver(): MorphTo
    {
        return $this->morphTo();
    }
}
