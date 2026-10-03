<?php

namespace Tests\Unit\Services;

use App\Services\Zaakiy\MetricComparisonEngine;
use Tests\TestCase;

class ZaakiyMetricComparisonTest extends TestCase
{
    public function test_comparison_calculates_delta_percentage_and_direction(): void
    {
        $result = app(MetricComparisonEngine::class)->compare('collections.collected_amount', 'Collected amount', 120, 100, 'AED', $this->range('2026-10-01', '2026-10-31'), $this->range('2026-09-01', '2026-09-30'));

        $this->assertSame(20, $result->absoluteDelta);
        $this->assertSame(20.0, $result->percentageDelta);
        $this->assertSame('increase', $result->direction);
    }

    public function test_zero_baseline_has_no_infinite_percentage(): void
    {
        $result = app(MetricComparisonEngine::class)->compare('properties.vacant_count', 'Vacant properties', 100, 0, 'count', $this->range('2026-10-01', '2026-10-31'), $this->range('2026-09-01', '2026-09-30'));

        $this->assertSame(100, $result->absoluteDelta);
        $this->assertNull($result->percentageDelta);
        $this->assertSame('increase', $result->direction);
        $this->assertSame('comparison_value_zero', $result->meta['percentage_unavailable_reason']);
    }

    public function test_equal_values_are_unchanged(): void
    {
        $result = app(MetricComparisonEngine::class)->compare('maintenance.completed_work_order_count', 'Completed work orders', 5, 5, 'count', $this->range('2026-10-01', '2026-10-31'), $this->range('2026-09-01', '2026-09-30'));

        $this->assertSame(0, $result->absoluteDelta);
        $this->assertSame(0.0, $result->percentageDelta);
        $this->assertSame('unchanged', $result->direction);
    }

    private function range(string $from, string $to): array
    {
        return ['from' => $from, 'to' => $to];
    }
}
