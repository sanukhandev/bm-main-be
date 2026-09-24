<?php

namespace App\Enums;

enum AgreementStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Commenced = 'commenced';
    case OnHold = 'on_hold';
    case Expired = 'expired';
    case Terminated = 'terminated';
    case Cancelled = 'cancelled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
