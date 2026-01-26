<?php

namespace PHPTools\Approval\Resolvers;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Request;
use PHPTools\Approval\Contracts\ColumnResolver;

class UrlResolver implements ColumnResolver
{
    public static function type(): string
    {
        return 'string';
    }

    public static function name(): string
    {
        return 'url';
    }

    public static function attributeCast()
    {
        return 'string';
    }

    public static function resolve()
    {
        if (App::runningInConsole()) {
            return static::resolveCommandLine();
        }

        return Request::fullUrl();
    }

    protected static function resolveCommandLine(): string
    {
        $command = Request::server('argv', null);

        if (is_array($command)) {
            return implode(' ', $command);
        }

        return 'console';
    }
}
