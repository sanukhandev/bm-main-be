<?php

namespace App\Services;

use App\Support\DecimalAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AgreementScheduleService
{
    public function create(string $type, int $agreementId, int $branchId, string $startDate, int $count, string|int|float $total, string $frequency, string $paymentMode): void
    {
        $table = $type === 'owner' ? 'owner_agreement_installments' : 'tenant_agreement_installments';
        $foreignKey = $type === 'owner' ? 'owner_agreement_id' : 'tenant_agreement_id';
        $totalCents = DecimalAmount::toCents((string) $total);
        $base = intdiv($totalCents, $count);
        $remainder = $totalCents - ($base * $count);
        $date = CarbonImmutable::parse($startDate);

        for ($number = 1; $number <= $count; $number++) {
            $step = match ($frequency) {
                'quarterly' => 3,
                'semi-annually' => 6,
                'annually' => 12,
                default => 1,
            };
            $amount = $base + ($number === 1 ? $remainder : 0);
            DB::table($table)->insert([
                'branch_id' => $branchId,
                $foreignKey => $agreementId,
                'installment_no' => $number,
                'due_date' => $date->toDateString(),
                'amount' => number_format($amount / 100, 2, '.', ''),
                'paid_amount' => 0,
                'payment_mode' => $paymentMode,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $date = $date->addMonths($step);
        }
    }

}
