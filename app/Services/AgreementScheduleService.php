<?php

namespace App\Services;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AgreementScheduleService
{
    public function create(string $type, int $agreementId, int $branchId, string $startDate, string $endDate, int $count, string|int|float $total, string $frequency, string $paymentMode, ?array $installments = null): void
    {
        $table = $type === 'owner' ? 'owner_agreement_installments' : 'tenant_agreement_installments';
        $foreignKey = $type === 'owner' ? 'owner_agreement_id' : 'tenant_agreement_id';
        $totalCents = Money::toCents($total);
        $base = intdiv($totalCents, $count);
        $remainder = $totalCents - ($base * $count);
        $date = CarbonImmutable::parse($startDate);
        $periodEnd = CarbonImmutable::parse($endDate);

        for ($number = 1; $number <= $count; $number++) {
            $line = $installments[$number - 1] ?? ['category' => 'rent', 'particulars' => 'Rent installment '.$number];
            $step = match ($frequency) {
                'quarterly' => 3,
                'semi-annually' => 6,
                'annually' => 12,
                default => 1,
            };
            $amount = array_key_exists('amount', $line) ? Money::toCents($line['amount']) : $base + ($number === 1 ? $remainder : 0);
            DB::table($table)->insert([
                'branch_id' => $branchId,
                $foreignKey => $agreementId,
                'installment_no' => $number,
                'due_date' => $line['due_date'] ?? ($date->gt($periodEnd) ? $periodEnd->toDateString() : $date->toDateString()),
                'amount' => Money::formatCents($amount),
                'paid_amount' => 0,
                'payment_mode' => $paymentMode,
                'category' => $line['category'],
                'particulars' => $line['particulars'],
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $date = $date->addMonths($step);
        }
    }
}
