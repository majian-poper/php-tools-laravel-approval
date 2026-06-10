<?php

namespace PHPTools\Approval\Exceptions;

use Illuminate\Contracts\Auth\Authenticatable;
use PHPTools\Approval\Enums\ApprovalStatus;
use PHPTools\Approval\Models\ApprovalTask;

class ChangeStatusFailedException extends \Exception
{
    public function __construct(
        public readonly ApprovalTask $approvalTask,
        public readonly ApprovalStatus $toStatus,
        public readonly Authenticatable $user,
    ) {
        parent::__construct(
            message: \sprintf(
                'Approval task [%s] could not change status to [%s] by [%s: %s]. reason: %s',
                $approvalTask->getKey(),
                $toStatus->value,
                $user->getAuthIdentifier(),
                $user->getAuthIdentifierName(),
                $this->getReason()
            )
        );
    }

    public function getReason(): string
    {
        if ($this->approvalTask->isExpired()) {
            return 'Approval task is expired.';
        }

        if (! $this->approvalTask->isPending()) {
            return 'Approval task is not pending.';
        }

        return 'User does not have permission to change status.';
    }
}
