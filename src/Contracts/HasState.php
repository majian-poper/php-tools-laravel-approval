<?php

namespace EnaTools\Approval\Contracts;

use EnaTools\Approval\Enums\ApprovalStatus;

interface HasState
{
    public function getStatus(): ApprovalStatus;
}
