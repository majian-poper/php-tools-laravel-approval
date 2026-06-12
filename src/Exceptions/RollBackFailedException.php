<?php

namespace PHPTools\Approval\Exceptions;

use Illuminate\Contracts\Auth\Authenticatable;
use PHPTools\Approval\Models\ApprovalTask;

class RollBackFailedException extends \Exception
{
    public function __construct(public readonly ApprovalTask $approvalTask, public readonly Authenticatable $user)
    {
        parent::__construct(
            message: \sprintf(
                'Approval task [%s] failed to roll back by [%s: %s]. reason: %s',
                $approvalTask->getKey(),
                $user->getAuthIdentifier(),
                $user->getAuthIdentifierName(),
                $this->getReason()
            )
        );
    }

    public function getReason(): string
    {
        if ($this->approvalTask->isRolledBack()) {
            return \sprintf('Already rolled back at %s', $this->approvalTask->rolled_back_at->toDateTimeString());
        }

        if (! $this->approvalTask->isApproved()) {
            return 'Only approved task can be rolled back';
        }

        return 'User does not have permission to roll back.';
    }
}
