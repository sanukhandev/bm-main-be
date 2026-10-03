<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\Skills\MaintenanceIntelligenceSkill;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class MaintenanceIntelligenceSkillTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_empty_branch_summary_is_scoped_and_does_not_invent_overdue(): void
    {
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));
        $result = app(MaintenanceIntelligenceSkill::class)->execute(new ZaakiyExecutionContext(
            $this->apiUser,
            $branch,
            new IntentFrame('maintenance_backlog', ['maintenance_intelligence'], 'search', question: 'How many work orders are open?'),
            now()->toIso8601String(),
        ));

        $this->assertSame(0, $result->summaryMetrics['open_work_order_count']);
        $this->assertSame(0, $result->summaryMetrics['properties_with_open_work_orders']);
        $this->assertSame(0, $result->summaryMetrics['high_priority_open_count']);
        $this->assertSame([], $result->records);
        $this->assertSame(false, $result->meta['overdue_available']);
        $this->assertSame('MAINTENANCE_OVERDUE_UNAVAILABLE', $result->warnings[0]['code']);
    }
}
