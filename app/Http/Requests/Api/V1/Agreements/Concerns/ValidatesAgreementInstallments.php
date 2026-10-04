<?php

namespace App\Http\Requests\Api\V1\Agreements\Concerns;

use Illuminate\Validation\Validator;

trait ValidatesAgreementInstallments
{
    protected function validateInstallmentSchedule(Validator $validator, int $count, mixed $total, ?string $startDate, ?string $endDate): void
    {
        $installments = $this->input('installments', []);
        $numbers = collect($installments)->pluck('installment_no')->sort()->values()->all();

        if (count($installments) !== $count || $numbers !== range(1, $count)) {
            $validator->errors()->add('installments', 'Installment numbers must run from 1 to the payment count.');
        }

        $hasAmounts = collect($installments)->every(static fn ($line): bool => is_array($line) && array_key_exists('amount', $line));
        $hasDates = collect($installments)->every(static fn ($line): bool => is_array($line) && array_key_exists('due_date', $line));
        $hasScheduleOverrides = collect($installments)->contains(static fn ($line): bool => is_array($line) && (array_key_exists('amount', $line) || array_key_exists('due_date', $line)));

        if (! $hasScheduleOverrides) {
            return;
        }

        if (! $hasAmounts || ! $hasDates) {
            $validator->errors()->add('installments', 'Every installment must include both a due date and an amount.');

            return;
        }

        $installments = collect($installments)->sortBy('installment_no')->values()->all();
        $totalCents = $this->cents((string) $total);
        $scheduleCents = collect($installments)->sum(fn (array $line): int => $this->cents((string) $line['amount']));
        if ($scheduleCents !== $totalCents) {
            $validator->errors()->add('installments', 'Installment amounts must add up exactly to the agreement total.');
        }

        $previousDate = null;
        foreach ($installments as $line) {
            $date = (string) $line['due_date'];
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            if (($startDate && $date < $startDate) || ($endDate && $date > $endDate)) {
                $validator->errors()->add('installments', 'Installment due dates must fall within the agreement period.');
                break;
            }
            if ($previousDate !== null && $date < $previousDate) {
                $validator->errors()->add('installments', 'Installment due dates must be in chronological order.');
                break;
            }
            $previousDate = $date;
        }
    }

    private function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
