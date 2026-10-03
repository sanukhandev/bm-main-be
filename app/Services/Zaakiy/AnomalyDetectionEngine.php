<?php

namespace App\Services\Zaakiy;

use App\Services\Zaakiy\DTOs\ZaakiyMetricAnomaly;

final class AnomalyDetectionEngine
{
    public const MAX_ANOMALIES = 10;

    public function significantDrop(array $comparison, string $code, string $rule, array $threshold, bool $partial = false): ?ZaakiyMetricAnomaly
    {
        if ($partial || ! is_numeric($comparison['current_value'] ?? null) || ! is_numeric($comparison['comparison_value'] ?? null) || ! is_numeric($comparison['absolute_delta'] ?? null) || ($comparison['percentage_delta'] ?? null) === null) {
            return null;
        }
        if ((float) $comparison['percentage_delta'] > (float) $threshold['percentage'] || (float) $comparison['absolute_delta'] > (float) $threshold['absolute']) {
            return null;
        }

        return new ZaakiyMetricAnomaly(
            code: $code,
            metric: $comparison['metric'],
            label: $comparison['label'],
            severity: 'attention',
            observedValue: $comparison['current_value'],
            baselineValue: $comparison['comparison_value'],
            absoluteDelta: $comparison['absolute_delta'],
            percentageDelta: $comparison['percentage_delta'],
            threshold: $threshold,
            period: $comparison['current_range'] ?? null,
            comparisonPeriod: $comparison['comparison_range'] ?? null,
            rule: $rule,
        );
    }

    public function significantIncrease(array $comparison, string $code, string $rule, array $threshold, bool $partial = false): ?ZaakiyMetricAnomaly
    {
        if ($partial || ! is_numeric($comparison['percentage_delta'] ?? null) || ! is_numeric($comparison['absolute_delta'] ?? null)) {
            return null;
        }
        if ((float) $comparison['percentage_delta'] < (float) $threshold['percentage'] || (float) $comparison['absolute_delta'] < (float) $threshold['absolute']) {
            return null;
        }

        return new ZaakiyMetricAnomaly($code, $comparison['metric'], $comparison['label'], 'attention', $comparison['current_value'], $comparison['comparison_value'], $comparison['absolute_delta'], $comparison['percentage_delta'], $threshold, $comparison['current_range'] ?? null, $comparison['comparison_range'] ?? null, $rule);
    }

    public function zeroInPeriod(array $comparison, string $code, string $rule, bool $partial = false): ?ZaakiyMetricAnomaly
    {
        if ($partial || (float) ($comparison['current_value'] ?? 0) !== 0.0 || (float) ($comparison['comparison_value'] ?? 0) <= 0) {
            return null;
        }

        return new ZaakiyMetricAnomaly($code, $comparison['metric'], $comparison['label'], 'attention', $comparison['current_value'], $comparison['comparison_value'], $comparison['absolute_delta'], $comparison['percentage_delta'], ['current' => 0, 'baseline_minimum' => 0.01], $comparison['current_range'] ?? null, $comparison['comparison_range'] ?? null, $rule);
    }

    public function condition(string $code, string $metric, string $label, string $severity, int|float $observed, array $threshold, string $rule, array $references = [], ?array $period = null, array $meta = []): ZaakiyMetricAnomaly
    {
        return new ZaakiyMetricAnomaly($code, $metric, $label, $severity, $observed, null, null, null, $threshold, $period, null, $rule, $references, $meta);
    }

    /** @param array<int, ZaakiyMetricAnomaly> $anomalies */
    public function sortAndBound(array $anomalies): array
    {
        $priority = ['critical' => 0, 'attention' => 1, 'info' => 2];
        usort($anomalies, static fn (ZaakiyMetricAnomaly $a, ZaakiyMetricAnomaly $b): int => ($priority[$a->severity] ?? 9) <=> ($priority[$b->severity] ?? 9) ?: strcmp($a->code, $b->code) ?: strcmp($a->metric, $b->metric));

        return array_slice($anomalies, 0, self::MAX_ANOMALIES);
    }
}
