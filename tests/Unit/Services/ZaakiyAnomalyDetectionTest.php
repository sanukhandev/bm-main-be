<?php

namespace Tests\Unit\Services;

use App\Services\Zaakiy\AnomalyDetectionEngine;
use Tests\TestCase;

class ZaakiyAnomalyDetectionTest extends TestCase
{
    public function test_significant_drop_requires_both_thresholds(): void
    {
        $comparison = [
            'metric' => 'collections.collected_amount',
            'label' => 'Collected amount',
            'current_value' => 150000,
            'comparison_value' => 220000,
            'absolute_delta' => -70000,
            'percentage_delta' => -31.82,
            'current_range' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
            'comparison_range' => ['from' => '2026-09-01', 'to' => '2026-09-30'],
        ];

        $anomaly = app(AnomalyDetectionEngine::class)->significantDrop($comparison, 'COLLECTION_DROP_SIGNIFICANT', 'explicit thresholds', ['percentage' => -25, 'absolute' => -10000]);

        $this->assertSame('COLLECTION_DROP_SIGNIFICANT', $anomaly->code);
        $this->assertSame('attention', $anomaly->severity);
        $this->assertSame(-70000, $anomaly->absoluteDelta);
        $this->assertNull(app(AnomalyDetectionEngine::class)->significantDrop($comparison, 'X', 'explicit thresholds', ['percentage' => -40, 'absolute' => -10000]));
    }

    public function test_partial_period_is_not_flagged(): void
    {
        $this->assertNull(app(AnomalyDetectionEngine::class)->significantDrop([
            'metric' => 'collections.collected_amount', 'label' => 'Collected amount',
            'current_value' => 1, 'comparison_value' => 100, 'absolute_delta' => -99,
            'percentage_delta' => -99, 'current_range' => [], 'comparison_range' => [],
        ], 'X', 'explicit thresholds', ['percentage' => -25, 'absolute' => -10], true));
    }

    public function test_anomalies_are_sorted_by_rule_severity_and_bounded(): void
    {
        $engine = app(AnomalyDetectionEngine::class);
        $rows = $engine->sortAndBound([
            $engine->condition('INFO_RULE', 'metric', 'Info', 'info', 1, [], 'info'),
            $engine->condition('CRITICAL_RULE', 'metric', 'Critical', 'critical', 1, [], 'critical'),
            $engine->condition('ATTENTION_RULE', 'metric', 'Attention', 'attention', 1, [], 'attention'),
        ]);

        $this->assertSame(['CRITICAL_RULE', 'ATTENTION_RULE', 'INFO_RULE'], array_map(fn ($row) => $row->code, $rows));
    }

    public function test_anomaly_serialization_is_stable(): void
    {
        $anomaly = app(AnomalyDetectionEngine::class)->condition('RULE', 'metric', 'Label', 'attention', 4, ['minimum' => 1], 'verified condition');

        $this->assertSame('RULE', $anomaly->toArray()['code']);
        $this->assertSame(4, $anomaly->toArray()['observed_value']);
        $this->assertSame('verified condition', $anomaly->toArray()['rule']);
    }
}
