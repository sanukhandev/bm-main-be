<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function formatCents(int $cents): string
    {
        $absolute = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function toCents(string|int|float $amount): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', (string) $amount, $parts)) {
            throw new InvalidArgumentException('Money must be a decimal with at most two fractional digits.');
        }
        $whole = ltrim($parts[2], '0') ?: '0';
        $maxWhole = '9999999999999999';
        if (strlen($whole) > strlen($maxWhole) || (strlen($whole) === strlen($maxWhole) && strcmp($whole, $maxWhole) > 0)) {
            throw new InvalidArgumentException('Money value is too large.');
        }
        $cents = (int) $whole * 100 + (int) str_pad($parts[3] ?? '', 2, '0');

        return ($parts[1] ?? '') === '-' ? -$cents : $cents;
    }
}
