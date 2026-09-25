<?php

namespace App\Services;

use App\Exceptions\ApiException;

final class PaymentLineState
{
    public static function assertCanChange(string $current, string $requested): void
    {
        if ($current === 'paid' && $requested !== 'paid') {
            throw new ApiException('FINANCIAL_RECORD_IMMUTABLE', 'Posted payment lines must be voided through their financial transaction.', 409);
        }
    }
}
