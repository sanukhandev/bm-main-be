<?php

namespace App\Support;

final class SqlLike
{
    public static function contains(string $value): string
    {
        return '%'.strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
    }
}
