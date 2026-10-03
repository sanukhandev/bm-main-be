<?php

namespace App\Services\Zaakiy;

use App\Services\Zaakiy\DTOs\ZaakiyMetricDriver;
use App\Services\Zaakiy\DTOs\ZaakiyMetricExplanation;

final class MetricExplanationEngine
{
    public const MAX_DRIVERS = 5;

    public function explainAdditive(string $metric, string $label, int|float $current, int|float $comparison, array $currentRange, array $comparisonRange, array $contributors, int $limit = self::MAX_DRIVERS): ZaakiyMetricExplanation
    {
        $drivers = [];
        foreach ($contributors as $contributor) {
            $currentValue = $this->number($contributor['current'] ?? 0);
            $comparisonValue = $this->number($contributor['comparison'] ?? 0);
            $delta = $this->number($currentValue - $comparisonValue);
            $drivers[] = new ZaakiyMetricDriver(
                dimension: (string) ($contributor['dimension'] ?? 'unknown'),
                key: (string) ($contributor['key'] ?? $contributor['label'] ?? ''),
                label: (string) ($contributor['label'] ?? $contributor['key'] ?? ''),
                currentValue: $currentValue,
                comparisonValue: $comparisonValue,
                absoluteDelta: $delta,
                direction: $delta === 0 ? 'unchanged' : ($delta > 0 ? 'increase' : 'decrease'),
                references: $contributor['references'] ?? [],
                meta: $contributor['meta'] ?? [],
            );
        }
        usort($drivers, static fn (ZaakiyMetricDriver $a, ZaakiyMetricDriver $b): int => abs($b->absoluteDelta) <=> abs($a->absoluteDelta) ?: strcmp($a->key, $b->key));
        $selected = array_slice($drivers, 0, max(1, min($limit, self::MAX_DRIVERS)));
        $explained = array_sum(array_map(static fn (ZaakiyMetricDriver $driver): int|float => $driver->absoluteDelta, $selected));
        $totalDelta = $current - $comparison;

        return new ZaakiyMetricExplanation(
            metric: $metric,
            label: $label,
            currentValue: $this->number($current),
            comparisonValue: $this->number($comparison),
            absoluteDelta: $this->number($totalDelta),
            currentRange: $currentRange,
            comparisonRange: $comparisonRange,
            drivers: $selected,
            residualDelta: $this->number($totalDelta - $explained),
            coveragePercentage: $totalDelta == 0 ? null : round(abs($explained) / abs($totalDelta) * 100, 2),
            meta: ['driver_count' => count($selected), 'total_contributor_count' => count($drivers)],
        );
    }

    private function number(int|float $value): int|float
    {
        return is_int($value) || floor($value) === $value ? (int) $value : round($value, 2);
    }
}
