<?php

namespace App\Enums;

enum PropertyUsage: string
{
    case Residential = 'residential';
    case Commercial = 'commercial';
    case Others = 'others';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
