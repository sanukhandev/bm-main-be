<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Services\Zaakiy\CompoundQueryExecutor;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\QueryPlanner;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class ZaakiyCompoundQueryTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_compound_executor_returns_bounded_independent_sections(): void
    {
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));
        $intent = new IntentFrame(
            'collections_summary',
            ['collections_health', 'vacancy_analysis'],
            'search',
            question: 'Show collections and vacancy.',
        );
        $execution = new ZaakiyExecutionContext($this->apiUser, $branch, $intent, now()->toIso8601String());
        $plan = app(QueryPlanner::class)->plan($intent);
        $result = app(CompoundQueryExecutor::class)->execute($plan, $execution);

        $this->assertInstanceOf(ZaakiySkillResult::class, $result);
        $this->assertSame(['collections_health', 'vacancy_analysis'], array_column($result->breakdowns['compound_sections'], 'capability'));
        $this->assertSame('parallel_sections', $result->meta['merge_strategy']);
        $this->assertTrue($result->meta['branch_scoped']);
        $this->assertTrue($result->meta['read_only']);
    }

    public function test_empty_reference_intersection_is_a_valid_bounded_result(): void
    {
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));
        $intent = new IntentFrame(
            'collections_summary',
            ['collections_health', 'vacancy_analysis'],
            'search',
            question: 'Show collections and vacancy in both lists.',
        );
        $execution = new ZaakiyExecutionContext($this->apiUser, $branch, $intent, now()->toIso8601String());
        $plan = app(QueryPlanner::class)->plan($intent);
        $result = app(CompoundQueryExecutor::class)->execute($plan, $execution);

        $this->assertTrue($plan->valid);
        $this->assertSame('intersection_by_reference', $plan->mergeStrategy);
        $this->assertSame(0, $result->summaryMetrics['correlated_count']);
        $this->assertNotEmpty($result->breakdowns['compound_sections']);
    }

    public function test_compound_comparison_and_trend_reuse_existing_engines(): void
    {
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));
        $range = ['from' => '2026-10-01', 'to' => '2026-10-03'];
        $comparison = ['from' => '2026-09-01', 'to' => '2026-09-03'];
        $intent = new IntentFrame(
            'collections_summary',
            ['collections_health', 'vacancy_analysis'],
            'aggregate',
            filters: ['trend' => true, 'trend_granularity' => 'day'],
            timeRange: $range,
            comparisonPeriod: $comparison,
            question: 'Compare collections and occupancy with last month.',
            comparisonRequested: true,
        );
        $execution = new ZaakiyExecutionContext($this->apiUser, $branch, $intent, now()->toIso8601String());
        $plan = app(QueryPlanner::class)->plan($intent);
        $result = app(CompoundQueryExecutor::class)->execute($plan, $execution);

        $this->assertTrue($plan->valid);
        $this->assertSame('compare', $plan->steps[0]->meta['operation']);
        $this->assertNotEmpty($result->comparisons);
    }

    public function test_compound_trend_is_bounded_and_normalized(): void
    {
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));
        $intent = new IntentFrame(
            'collections_summary',
            ['collections_health', 'vacancy_analysis'],
            'aggregate',
            filters: ['trend' => true, 'trend_granularity' => 'day'],
            timeRange: ['from' => '2026-10-01', 'to' => '2026-10-03'],
            question: 'Show collections and vacancy trends daily.',
        );
        $execution = new ZaakiyExecutionContext($this->apiUser, $branch, $intent, now()->toIso8601String());
        $plan = app(QueryPlanner::class)->plan($intent);
        $result = app(CompoundQueryExecutor::class)->execute($plan, $execution);

        $this->assertTrue($plan->valid);
        $this->assertNotEmpty($result->trends);
        $this->assertLessThanOrEqual(24, count($result->trends[0]['points']));
    }
}
