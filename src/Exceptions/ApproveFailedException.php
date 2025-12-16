<?php

namespace EnaTools\Approval\Exceptions;

use EnaTools\Approval\Models\ApprovalTask;

class ApproveFailedException extends \Exception
{
    public function __construct(public readonly ApprovalTask $approvalTask, $previous = null)
    {
        parent::__construct(
            message: \sprintf(
                'Approval task [%s] failed to approve. %s',
                $approvalTask->getKey(),
                $previous?->getMessage() ?? ''
            ),
            previous: $previous
        );
    }
}
