<?php

namespace PHPTools\Approval\Exceptions;

use PHPTools\Approval\Models\ApprovalTask;

class ApprovalTaskExpiredException extends \Exception
{
    public function __construct(public readonly ApprovalTask $approvalTask)
    {
        parent::__construct(
            \sprintf(
                'Approval task [%s] was expired at %s.',
                $approvalTask->getKey(),
                $approvalTask->expires_at->toDateTimeString()
            )
        );
    }
}
