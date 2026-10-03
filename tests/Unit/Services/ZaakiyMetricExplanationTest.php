<?php

namespace Tests\Unit\Services;

use App\Services\Zaakiy\MetricExplanationEngine;
use Tests\TestCase;

class ZaakiyMetricExplanationTest extends TestCase
{
    public function test_additive_drivers_are_sorted_bounded_and_leave_residual(): void
    {
        $result = app(MetricExplanationEngine::class)->explainAdditive(
            'collections.collected_amount',
            'Collected amount',
            150,
            100,
            ['from' => '2026-10-01', 'to' => '2026-10-31'],
            ['from' => '2026-09-01', 'to' => '2026-09-30'],
            [
                ['key' => 'a', 'label' => 'A', 'current' => 120, 'comparison' => 80],
                ['key' => 'b', 'label' => 'B', 'current' => 20, 'comparison' => 25],
                ['key' => 'c', 'label' => 'C', 'current' => 5, 'comparison' => 0],
            ],
            2,
        );

        $this->assertSame(['a', 'b'], array_column($result->toArray()['drivers'], 'key'));
        $this->assertSame(15, $result->residualDelta);
        $this->assertSame(70.0, $result->coveragePercentage);
        $this->assertSame('decrease', $result->drivers[1]->direction);
    }
}
