<?php

namespace PHPTools\Approval\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use PHPTools\Approval\Contracts;
use PHPTools\Approval\Enums;
use PHPTools\Approval\Events;
use PHPTools\Approval\Exceptions;
use PHPTools\Approval\Facades\ApprovalFacade;

/**
 * @property int $approval_task_id
 * @property int $order_number
 * @property int $approver_id
 * @property string $approver_type
 * @property Enums\ApprovalStatus $status
 * @property string|null $comment
 * @property \Carbon\CarbonImmutable|null $approved_at
 *
 * @property-read ApprovalTask $task
 * @property-read Contracts\Approver & Model $approver
 * @property-read Authenticatable & Model $user
 */
class ApprovalStep extends Model implements Contracts\HasState
{
    use Concerns\InteractsWithState;

    protected $fillable = [
        'approval_task_id',
        'order_number',
        'approver_type',
        'approver_id',
        'user_type',
        'user_id',
        'status',
        'comment',
        'approved_at',
    ];

    protected $casts = [
        'approval_task_id' => 'int',
        'order_number' => 'int',
        'approver_type' => 'string',
        'approver_id' => 'int',
        'user_type' => 'string',
        'user_id' => 'int',
        'status' => Enums\ApprovalStatus::class,
        'comment' => 'string',
        'approved_at' => 'immutable_datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(
            config('approval.implementations.approval_task', ApprovalTask::class),
            'approval_task_id'
        );
    }

    public function approver(): MorphTo
    {
        return $this->morphTo('approver');
    }

    public function user(): MorphTo
    {
        return $this->morphTo('user');
    }

    public function contains(Authenticatable $user): bool
    {
        return $this->approver->contains($user);
    }

    public function isReviewedBy(Authenticatable $user): bool
    {
        return $this->user?->is($user) ?? false;
    }

    public function approve(string $comment = ''): bool
    {
        return $this->approveBy(ApprovalFacade::resolveUser(), $comment);
    }

    /**
     * @param Authenticatable & Model $user
     * @param string $comment
     *
     * @return bool
     */
    public function approveBy(Authenticatable $user, string $comment = ''): bool
    {
        $this->expectStatus(Enums\ApprovalStatus::PENDING);

        $this->expectContainsUser($user);

        $this->comment = $comment;

        $this->user()->associate($user);

        $this->markAsApproved();

        $saved = $this->save();

        if ($saved) {
            event(new Events\ApprovalStepApproved($this));
        }

        return $saved;
    }

    public function reject(string $comment = ''): bool
    {
        return $this->rejectBy(ApprovalFacade::resolveUser(), $comment);
    }

    /**
     * @param Authenticatable & Model $user
     * @param string $comment
     *
     * @return bool
     */
    public function rejectBy(Authenticatable $user, string $comment = ''): bool
    {
        $this->expectStatus(Enums\ApprovalStatus::PENDING);

        $this->expectContainsUser($user);

        $this->comment = $comment;

        $this->user()->associate($user);

        $this->markAsRejected();

        $saved = $this->save();

        if ($saved) {
            event(new Events\ApprovalStepRejected($this));
        }

        return $saved;
    }

    protected function expectContainsUser(Authenticatable $user): void
    {
        throw_unless($this->contains($user), Exceptions\ApproverNotMatchException::class, $user, $this);
    }
}
