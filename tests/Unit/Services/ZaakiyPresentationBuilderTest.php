<?php

namespace Tests\Unit\Services;

use App\Services\Zaakiy\ZaakiyPresentationBuilder;
use Tests\TestCase;

class ZaakiyPresentationBuilderTest extends TestCase
{
    public function test_verified_result_is_projected_into_bounded_structured_events(): void
    {
        $result = app(ZaakiyPresentationBuilder::class)->build([
            'evidence' => [[
                'summary_metrics' => ['collections.collected_amount' => '314200.00'],
                'records' => array_fill(0, 20, ['type' => 'property', 'id' => 1, 'property_code' => 'P-001', 'secret' => 'hidden']),
                'comparisons' => [['metric' => 'collections.collected_amount', 'current_value' => 120, 'comparison_value' => 100, 'percentage_delta' => 20]],
                'trends' => [['metric' => 'collections.collected_amount', 'points' => []]],
                'warnings' => [['code' => 'FINANCIAL_DATA_RESTRICTED']],
                'suggested_followups' => ['Compare with last month.'],
            ]],
        ]);

        $this->assertSame(['summary', 'records', 'comparison', 'trend', 'warnings', 'suggestions'], array_column($result, 'event'));
        $this->assertCount(10, $result[1]['data']['records']);
        $this->assertArrayNotHasKey('secret', $result[1]['data']['records'][0]['fields']);
        $this->assertSame('AED', $result[0]['data']['metrics'][0]['unit']);
    }

    public function test_empty_and_unsupported_payloads_are_omitted(): void
    {
        $result = app(ZaakiyPresentationBuilder::class)->build(['evidence' => [['summary_metrics' => [], 'records' => []]]]);

        $this->assertSame([], $result);
    }
}
