<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\Skills\ManagementBriefingSkill;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class ManagementBriefingSkillTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_standard_briefing_has_bounded_deterministic_sections(): void
    {
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));
        $result = app(ManagementBriefingSkill::class)->execute(new ZaakiyExecutionContext(
            $this->apiUser,
            $branch,
            new IntentFrame('management_briefing', ['management_briefing'], 'aggregate', question: 'Give me a management briefing.'),
            now()->toIso8601String(),
        ));

        $this->assertInstanceOf(ZaakiySkillResult::class, $result);
        $this->assertSame(['attention', 'collections', 'occupancy', 'agreements', 'renewals', 'maintenance'], array_column($result->breakdowns['briefing_sections'], 'code'));
        $this->assertSame('empty', $result->breakdowns['briefing_sections'][0]['status']);
        $this->assertTrue($result->meta['branch_scoped']);
        $this->assertTrue($result->meta['read_only']);
    }
}
