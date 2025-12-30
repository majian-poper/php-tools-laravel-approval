<?php

namespace PHPTools\Approval\Contracts;

interface ApproverResolver
{
    /**
     * @return Approver & \Illuminate\Database\Eloquent\Model
     */
    public static function resolve(): Approver;
}
