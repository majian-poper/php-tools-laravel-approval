<?php

namespace PHPTools\Approval\Resolvers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use PHPTools\Approval\Contracts\UserResolver as Resolver;
use PHPTools\Approval\Exceptions\UnauthorizedException;

class UserResolver implements Resolver
{
    public static function resolve(): Authenticatable
    {
        $guards = config('approval.user.guards', [config('auth.defaults.guard')]);

        foreach ($guards as $guard) {
            try {
                $authenticated = Auth::guard($guard)->check();
            } catch (\Exception $e) {
                continue;
            }

            if ($authenticated === true) {
                return Auth::guard($guard)->user();
            }
        }

        throw new UnauthorizedException(message: 'No authenticated user found.');
    }
}
