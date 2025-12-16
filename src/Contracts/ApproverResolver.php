<?php

namespace EnaTools\Approval\Contracts;

interface ApproverResolver
{
    /**
     * @return Approver & \Illuminate\Database\Eloquent\Model
     */
    public static function resolve(): Approver;
}
