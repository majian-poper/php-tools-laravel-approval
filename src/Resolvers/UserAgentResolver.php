<?php

namespace EnaTools\Approval\Resolvers;

use EnaTools\Approval\Contracts\ColumnResolver;
use Illuminate\Support\Facades\Request;

class UserAgentResolver implements ColumnResolver
{
    public static function type(): string
    {
        return 'string';
    }

    public static function name(): string
    {
        return 'user_agent';
    }

    public static function attributeCast()
    {
        return 'string';
    }

    public static function resolve()
    {
        return Request::header('User-Agent', 'Unknown User Agent');
    }
}
