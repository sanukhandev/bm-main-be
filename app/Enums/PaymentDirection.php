<?php

namespace App\Enums;

enum PaymentDirection: string
{
    case Inward = 'inward';
    case Outward = 'outward';
}
