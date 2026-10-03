<?php

namespace App\Services\Zaakiy;

use App\Services\Zaakiy\DTOs\ZaakiyMetricComparison;

final class MetricComparisonEngine
{
    public function compare(string $metric, string $label, int|float $current, int|float $comparison, string $unit, array $currentRange, array $comparisonRange, array $meta = []): ZaakiyMetricComparison
    {
        $delta = $current - $comparison;
        $percentage = $comparison == 0 ? null : round(($delta / $comparison) * 100, 2);

        return new ZaakiyMetricComparison(
            metric: $metric,
            label: $label,
            currentValue: $current,
            comparisonValue: $comparison,
            absoluteDelta: $this->number($delta),
            percentageDelta: $percentage,
            direction: $delta === 0.0 || $delta === 0 ? 'unchanged' : ($delta > 0 ? 'increase' : 'decrease'),
            unit: $unit,
            currentRange: $currentRange,
            comparisonRange: $comparisonRange,
            meta: $meta + ($comparison == 0 ? ['percentage_unavailable_reason' => 'comparison_value_zero'] : []),
        );
    }

    private function number(int|float $value): int|float
    {
        return is_int($value) || floor($value) === $value ? (int) $value : round($value, 2);
    }
}
