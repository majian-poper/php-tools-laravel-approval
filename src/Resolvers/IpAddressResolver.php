<?php

namespace PHPTools\Approval\Resolvers;

use Illuminate\Support\Facades\Request;
use PHPTools\Approval\Contracts\ColumnResolver;

class IpAddressResolver implements ColumnResolver
{
    public static function type(): string
    {
        return 'ipAddress';
    }

    public static function name(): string
    {
        return 'ip_address';
    }

    public static function attributeCast()
    {
        return 'string';
    }

    public static function resolve()
    {
        return Request::ip() ?: '127.0.0.1';
    }
}
