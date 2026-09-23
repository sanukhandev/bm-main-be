<?php

namespace App\Enums;

enum ChequeStatus: string
{
    case Received = 'received';
    case Deposited = 'deposited';
    case Cleared = 'cleared';
    case Bounced = 'bounced';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::Received => [self::Deposited->value, self::Cancelled->value],
            self::Deposited => [self::Cleared->value, self::Bounced->value, self::Cancelled->value],
            default => [],
        };
    }
}
