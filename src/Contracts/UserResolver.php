<?php

namespace PHPTools\Approval\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface UserResolver
{
    public static function resolve(): Authenticatable;
}
