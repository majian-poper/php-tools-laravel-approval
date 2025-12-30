<?php

namespace PHPTools\Approval\Exceptions;

class NoApprovableForCreationException extends \Exception
{
    public function __construct()
    {
        parent::__construct('No approvable defined for approval task creation.');
    }
}
