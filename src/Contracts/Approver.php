<?php

namespace PHPTools\Approval\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface Approver
{
    public function getApproverTitleAttribute(): string;

    public function contains(Authenticatable $user): bool;

    public function canRollBack(): bool;
}
