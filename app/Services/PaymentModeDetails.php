<?php

namespace App\Services;

final class PaymentModeDetails
{
    public static function normalize(array $data, ?int $sequence = null): array
    {
        $mode = $data['payment_mode'];
        foreach (['cheque_no', 'cheque_date', 'bank_name', 'bank_reference', 'transfer_date'] as $field) {
            if (($mode === 'cash') || ($mode === 'cheque' && in_array($field, ['bank_reference', 'transfer_date'], true)) || ($mode === 'bank_transfer' && in_array($field, ['cheque_no', 'cheque_date'], true))) {
                $data[$field] = null;
            }
        }

        if (blank($data['remarks'] ?? null)) {
            $data['remarks'] = match ($mode) {
                'cheque' => 'Cheque '.($data['cheque_no'] ?? ''),
                'bank_transfer' => 'Bank transfer '.($data['bank_reference'] ?? ''),
                default => 'Cash Payment '.($sequence ?? ''),
            };
        }

        return $data;
    }
}
