<?php

namespace PHPTools\Approval\Contracts;

use PHPTools\Approval\Enums\ApprovalStatus;

interface HasState
{
    public function getStatus(): ApprovalStatus;
}
