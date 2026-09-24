<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final readonly class IntelligentReportPeriod
{
    public function __construct(public string $key, public string $from, public string $to, public string $granularity, public ?string $comparisonFrom = null, public ?string $comparisonTo = null) {}

    public static function resolve(?string $key, ?string $from, ?string $to): self
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $key ??= 'this_month';
        if ($key === 'custom') {
            if (! $from || ! $to || $from > $to) {
                throw ValidationException::withMessages(['date_from' => 'A valid custom date range is required.']);
            }
            $start = CarbonImmutable::parse($from, config('app.timezone'));
            $end = CarbonImmutable::parse($to, config('app.timezone'));
        } else {
            [$start, $end] = match ($key) {
                'last_month' => [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()],
                'last_3_months' => [$now->subMonths(2)->startOfMonth(), $now->endOfMonth()],
                'last_6_months' => [$now->subMonths(5)->startOfMonth(), $now->endOfMonth()],
                'last_12_months' => [$now->subMonths(11)->startOfMonth(), $now->endOfMonth()],
                'this_year' => [$now->startOfYear(), $now->endOfYear()],
                default => [$now->startOfMonth(), $now->endOfMonth()],
            };
        }
        $days = $start->diffInDays($end) + 1;
        $comparisonDays = max(1, $days);
        $comparisonTo = $start->subDay();
        $comparisonFrom = $comparisonTo->subDays($comparisonDays - 1);

        return new self($key, $start->toDateString(), $end->toDateString(), $days <= 31 ? 'daily' : 'monthly', $comparisonFrom->toDateString(), $comparisonTo->toDateString());
    }

    public function toArray(): array
    {
        return ['key' => $this->key, 'from' => $this->from, 'to' => $this->to, 'granularity' => $this->granularity, 'comparison_from' => $this->comparisonFrom, 'comparison_to' => $this->comparisonTo];
    }
}
