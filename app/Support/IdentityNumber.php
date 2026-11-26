<?php

namespace App\Support;

final class IdentityNumber
{
    public static function mask(string $value): string
    {
        $remaining = preg_match_all('/[A-Za-z0-9]/', $value) - 4;

        return preg_replace_callback('/[A-Za-z0-9]/', static function (array $match) use (&$remaining): string {
            if ($remaining > 0) {
                $remaining--;

                return '*';
            }

            return $remaining < 0 ? '*' : $match[0];
        }, $value) ?? $value;
    }
}
