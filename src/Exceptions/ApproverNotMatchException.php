<?php

namespace PHPTools\Approval\Exceptions;

use Illuminate\Contracts\Auth\Authenticatable;
use PHPTools\Approval\Models\ApprovalStep;

class ApproverNotMatchException extends \Exception
{
    /**
     * @param Authenticatable & \Illuminate\Database\Eloquent\Model $user
     */
    public function __construct(public readonly Authenticatable $user, public readonly ApprovalStep $step)
    {
        parent::__construct(
            message: \sprintf(
                'User %s[%s] does not match the expected approver type %s[%s] for %s [%s].',
                $user->getMorphClass(),
                $user->getKey(),
                $step->approver->getMorphClass(),
                $step->approver->getKey(),
                $step->getMorphClass(),
                $step->getKey()
            )
        );
    }
}
