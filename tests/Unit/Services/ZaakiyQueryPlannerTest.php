<?php

namespace Tests\Unit\Services;

use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\QueryPlanner;
use Tests\TestCase;

class ZaakiyQueryPlannerTest extends TestCase
{
    public function test_direct_read_resolves_metric_and_capability(): void
    {
        $plan = app(QueryPlanner::class)->plan(new IntentFrame('vacancy_analysis', ['vacancy_analysis'], 'search'));

        $this->assertTrue($plan->valid);
        $this->assertSame('vacancy_analysis', $plan->capability);
        $this->assertSame('properties.vacant_count', $plan->metric);
        $this->assertSame('read', $plan->operation);
    }

    public function test_comparison_trend_explanation_and_anomaly_plans_are_bounded(): void
    {
        $planner = app(QueryPlanner::class);
        $range = ['from' => '2026-10-01', 'to' => '2026-10-31'];
        $comparison = ['from' => '2026-09-01', 'to' => '2026-09-30'];

        $compare = $planner->plan(new IntentFrame('collections_summary', ['collections_health'], 'aggregate', timeRange: $range, comparisonPeriod: $comparison, comparisonRequested: true));
        $trend = $planner->plan(new IntentFrame('collections_summary', ['collections_health'], 'aggregate', filters: ['trend' => true, 'trend_granularity' => 'month'], timeRange: $range));
        $explain = $planner->plan(new IntentFrame('collections_summary', ['collections_health'], 'aggregate', timeRange: $range, comparisonPeriod: $comparison, comparisonRequested: true, explanationRequested: true));
        $anomaly = $planner->plan(new IntentFrame('collections_summary', ['collections_health'], 'aggregate', comparisonPeriod: $comparison, comparisonRequested: true, anomalyRequested: true));

        $this->assertSame('compare', $compare->operation);
        $this->assertCount(3, $compare->steps);
        $this->assertSame('trend', $trend->operation);
        $this->assertSame('month', $trend->granularity);
        $this->assertSame('explain', $explain->operation);
        $this->assertSame('anomaly', $anomaly->operation);
    }

    public function test_registry_validates_filters_features_and_entities(): void
    {
        $planner = app(QueryPlanner::class);
        $valid = $planner->plan(new IntentFrame('vacancy_analysis', ['vacancy_analysis'], 'search', filters: ['property_type' => 'apartment']));
        $invalidFilter = $planner->plan(new IntentFrame('maintenance_priority', ['maintenance_intelligence'], 'search', filters: ['priority' => 'catastrophic']));
        $invalidFeature = $planner->plan(new IntentFrame('maintenance_backlog', ['maintenance_intelligence'], 'search', filters: ['trend' => true, 'trend_granularity' => 'month']));
        $missingEntity = $planner->plan(new IntentFrame('property_360', ['property_360'], 'detail'));

        $this->assertTrue($valid->valid);
        $this->assertFalse($invalidFilter->valid);
        $this->assertSame('FILTER_UNSUPPORTED', $invalidFilter->warnings[0]['code']);
        $this->assertFalse($invalidFeature->valid);
        $this->assertSame('HISTORICAL_SNAPSHOT_UNAVAILABLE', $invalidFeature->warnings[0]['code']);
        $this->assertFalse($missingEntity->valid);
        $this->assertContains('ENTITY_REQUIRED', array_column($missingEntity->warnings, 'code'));
    }

    public function test_briefing_compound_and_write_requests_are_handled_safely(): void
    {
        $planner = app(QueryPlanner::class);
        $briefing = $planner->plan(new IntentFrame('management_briefing', ['management_briefing'], 'aggregate'));
        $compound = $planner->plan(new IntentFrame('collections_summary', ['collections_health', 'vacancy_analysis'], 'aggregate', question: 'Show collections and vacancy.'));
        $write = $planner->plan(new IntentFrame('agreement_360', ['agreement_360'], 'detail', entities: ['agreement'], question: 'Renew this agreement.'));

        $this->assertTrue($briefing->valid);
        $this->assertSame('briefing', $briefing->operation);
        $this->assertTrue($compound->valid);
        $this->assertSame('compound', $compound->operation);
        $this->assertSame('parallel_sections', $compound->mergeStrategy);
        $this->assertSame(['collections_health', 'vacancy_analysis'], $compound->capabilities);
        $this->assertCount(3, $compound->steps);
        $this->assertFalse($write->valid);
        $this->assertSame('WRITE_ACTION_UNSUPPORTED', $write->warnings[0]['code']);
    }

    public function test_compound_filters_are_propagated_only_to_supporting_capabilities(): void
    {
        $plan = app(QueryPlanner::class)->plan(new IntentFrame(
            'collections_summary',
            ['collections_health', 'vacancy_analysis'],
            'search',
            filters: ['property_type' => 'apartment'],
            question: 'Show collections and vacancy for apartments.',
        ));

        $this->assertTrue($plan->valid, json_encode($plan->warnings));
        $this->assertSame([], $plan->steps[0]->input['filters']);
        $this->assertSame(['property_type' => 'apartment'], $plan->steps[1]->input['filters']);
    }

    public function test_compound_intersection_requires_a_supported_reference_type(): void
    {
        $plan = app(QueryPlanner::class)->plan(new IntentFrame(
            'collections_summary',
            ['collections_health', 'vacancy_analysis'],
            'search',
            question: 'Show collections and vacancy in both lists.',
        ));

        $this->assertTrue($plan->valid);
        $this->assertSame('intersection_by_reference', $plan->mergeStrategy);
        $this->assertSame('property', $plan->correlation);
    }

    public function test_compound_explanations_are_bounded(): void
    {
        $plan = app(QueryPlanner::class)->plan(new IntentFrame(
            'collections_summary',
            ['collections_health', 'vacancy_analysis'],
            'search',
            explanationRequested: true,
            question: 'Why did collections and vacancy change?',
        ));

        $this->assertFalse($plan->valid);
        $this->assertContains('PLAN_TOO_COMPLEX', array_column($plan->warnings, 'code'));
    }
}
