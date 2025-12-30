<?php

namespace PHPTools\Approval\Exceptions;

class NoApproverForCreationException extends \Exception
{
    public function __construct()
    {
        parent::__construct('No approver defined for approval task creation.');
    }
}
