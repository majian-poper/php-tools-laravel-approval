<?php

namespace EnaTools\Approval\Exceptions;

use EnaTools\Approval\Contracts\HasState;
use EnaTools\Approval\Enums\ApprovalStatus;

class UnexpectedApprovalStatusException extends \Exception
{
    /**
     * @param ApprovalStatus $expectedStatus
     * @param HasState & \Illuminate\Database\Eloquent\Model $model
     */
    public function __construct(ApprovalStatus $expectedStatus, HasState $model)
    {
        parent::__construct(
            \sprintf(
                'Unexpected approval status: expected %s, but got %s from model %s [%s].',
                $expectedStatus->value,
                $model->getStatus()->value,
                $model->getMorphClass(),
                $model->getKey()
            )
        );
    }
}
