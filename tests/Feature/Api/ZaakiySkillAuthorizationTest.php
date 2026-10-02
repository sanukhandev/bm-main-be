<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\LegacySkillAdapter;
use App\Services\Zaakiy\Skills\AuditSkill;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class ZaakiySkillAuthorizationTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_audit_skill_result_respects_branch_and_permission_boundaries(): void
    {
        $intent = new IntentFrame('audit.recent_activity', ['audit'], 'search', question: 'show audit activity');
        $skill = app(AuditSkill::class);
        $adapter = new LegacySkillAdapter('audit', $skill);

        $allowedBranch = new BranchContext;
        $allowedBranch->set(Branch::query()->findOrFail($this->branchA));
        $allowed = $adapter->execute(new ZaakiyExecutionContext($this->apiUser, $allowedBranch, $intent, now()->toIso8601String()));

        $restrictedBranch = new BranchContext;
        $restrictedBranch->set(Branch::query()->findOrFail($this->branchB));
        $restricted = $adapter->execute(new ZaakiyExecutionContext($this->apiUser, $restrictedBranch, $intent, now()->toIso8601String()));

        $this->assertInstanceOf(ZaakiySkillResult::class, $allowed);
        $this->assertTrue($allowed->summaryMetrics['available']);
        $this->assertSame('/app/administration/audit', $allowed->navigation[0]['route']);
        $this->assertTrue($restricted->summaryMetrics['restricted']);
        $this->assertSame([], $restricted->navigation);
    }
}
