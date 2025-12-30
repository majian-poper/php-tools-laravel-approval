<?php

namespace PHPTools\Approval\Exceptions;

use PHPTools\Approval\Models\ApprovalTask;

class RollBackFailedException extends \Exception
{
    public function __construct(public readonly ApprovalTask $approvalTask, $previous = null)
    {
        parent::__construct(
            message: \sprintf(
                'Approval task [%s] failed to roll back. %s',
                $approvalTask->getKey(),
                $approvalTask->isRolledBack()
                    ? \sprintf('Already rolled back at %s', $approvalTask->rolled_back_at->toDateTimeString())
                    : ($previous?->getMessage() ?? '')
            ),
            previous: $previous
        );
    }
}
