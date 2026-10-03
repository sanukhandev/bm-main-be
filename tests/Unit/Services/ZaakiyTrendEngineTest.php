<?php

namespace Tests\Unit\Services;

use App\Services\Zaakiy\DTOs\ZaakiyMetricTrendPoint;
use App\Services\Zaakiy\TrendEngine;
use Tests\TestCase;

class ZaakiyTrendEngineTest extends TestCase
{
    public function test_generates_months_and_first_to_last_summary(): void
    {
        $engine = app(TrendEngine::class);
        $plan = $engine->periods(['from' => '2026-01-15', 'to' => '2026-03-20'], 'month');

        $this->assertSame('month', $plan['granularity']);
        $this->assertSame(['from' => '2026-01-15', 'to' => '2026-01-31', 'label' => 'Jan 2026'], $plan['periods'][0]);
        $this->assertCount(3, $plan['periods']);

        $trend = $engine->build('collections.collected_amount', 'Collected amount', 'AED', 'month', ['from' => '2026-01-15', 'to' => '2026-03-20'], [
            new ZaakiyMetricTrendPoint('2026-01-15', '2026-01-31', 'Jan 2026', 100),
            new ZaakiyMetricTrendPoint('2026-02-01', '2026-02-28', 'Feb 2026', 120),
        ]);

        $this->assertSame(20, $trend->summary['absolute_delta']);
        $this->assertSame(20.0, $trend->summary['percentage_delta']);
        $this->assertSame('increase', $trend->summary['direction']);
    }

    public function test_explicit_daily_request_is_bounded(): void
    {
        $plan = app(TrendEngine::class)->periods(['from' => '2026-01-01', 'to' => '2026-02-28'], 'day');

        $this->assertSame('TREND_RANGE_TOO_LARGE', $plan['warnings'][0]['code']);
        $this->assertSame([], $plan['periods']);
    }
}
