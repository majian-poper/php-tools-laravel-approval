<?php

namespace PHPTools\Approval\Contracts;

interface ColumnResolver
{
    public static function type(): string;

    public static function name(): string;

    public static function attributeCast();

    public static function resolve();
}
