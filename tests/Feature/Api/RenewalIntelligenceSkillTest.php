<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\Skills\RenewalIntelligenceSkill;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class RenewalIntelligenceSkillTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_default_window_includes_sixty_days_and_maps_risk_signals(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $propertyId = $this->property('P-A-RENEW', 'Flat Renewal');
        $ownerId = $this->ownerAgreement($propertyId, now()->addDays(14)->toDateString());
        $tenantId = $this->tenantAgreement($propertyId, $ownerId, now()->addDays(60)->toDateString());
        DB::table('tenant_agreement_installments')->insert([
            'branch_id' => $this->branchA,
            'tenant_agreement_id' => $tenantId,
            'installment_no' => 1,
            'due_date' => now()->subDay()->toDateString(),
            'amount' => 10000,
            'paid_amount' => 4000,
            'payment_mode' => 'cash',
            'status' => 'partially_paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->runSkill();
        $tenant = collect($result->records)->firstWhere('agreement_no', 'TA-RENEW-001');

        $this->assertInstanceOf(ZaakiySkillResult::class, $result);
        $this->assertNotNull($tenant);
        $this->assertContains('RENEWAL_DUE', $tenant['renewal_signals']);
        $this->assertNotContains('RENEWAL_DUE_SOON', $tenant['renewal_signals']);
        $this->assertContains('RENEWAL_WITH_OUTSTANDING', $tenant['renewal_signals']);
        $this->assertContains('RENEWAL_WITH_OVERDUE', $tenant['renewal_signals']);
        $this->assertSame('6000.00', $tenant['outstanding']);
        $this->assertSame('6000.00', $tenant['overdue']);
        $owner = collect($result->records)->firstWhere('agreement_no', 'OA-RENEW-001');
        $this->assertSame(1, $owner['portfolio_impact']['linked_property_count']);
        $this->assertSame(1, $owner['portfolio_impact']['occupied_property_count']);
    }

    public function test_financial_filters_and_permission_degradation_are_safe(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $propertyId = $this->property('P-A-RENEW-FILTER', 'Flat Renewal Filter');
        $ownerId = $this->ownerAgreement($propertyId, now()->addDays(20)->toDateString());
        $tenantId = $this->tenantAgreement($propertyId, $ownerId, now()->addDays(20)->toDateString());
        DB::table('tenant_agreement_installments')->insert([
            'branch_id' => $this->branchA,
            'tenant_agreement_id' => $tenantId,
            'installment_no' => 1,
            'due_date' => now()->addDay()->toDateString(),
            'amount' => 10000,
            'paid_amount' => 10000,
            'payment_mode' => 'cash',
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->runSkill(['financial_state' => 'no_outstanding']);
        $this->assertSame(0, $result->summaryMetrics['renewal_with_outstanding_count']);
        $this->assertCount(2, $result->records);

        $roleId = DB::table('roles')->where('key', 'branch_admin')->value('id');
        $permissionId = DB::table('permissions')->where('key', 'accounts.view')->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        $restricted = $this->runSkill();
        $this->assertFalse($restricted->meta['financial_included']);
        $this->assertArrayNotHasKey('outstanding', $restricted->records[0] ?? []);
        $this->assertSame('FINANCIAL_DATA_RESTRICTED', $restricted->warnings[0]['code']);
    }

    private function runSkill(array $filters = []): ZaakiySkillResult
    {
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));

        return app(RenewalIntelligenceSkill::class)->execute(new ZaakiyExecutionContext(
            $this->apiUser,
            $branch,
            new IntentFrame('renewal_intelligence', ['renewal_intelligence'], 'search', filters: $filters, question: 'Which agreements need renewal?'),
            now()->toIso8601String(),
        ));
    }

    private function property(string $code, string $name): int
    {
        return DB::table('properties')->insertGetId([
            'branch_id' => $this->branchA,
            'owner_customer_id' => $this->customerA,
            'property_code' => $code,
            'unit_number' => '204',
            'property_type' => 'apartment',
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ownerAgreement(int $propertyId, string $endDate): int
    {
        $id = DB::table('owner_agreements')->insertGetId([
            'branch_id' => $this->branchA,
            'agreement_no' => 'OA-RENEW-001',
            'owner_customer_id' => $this->customerA,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => $endDate,
            'total_amount' => 60000,
            'currency_code' => 'AED',
            'payment_count' => 2,
            'payment_mode' => 'bank_transfer',
            'status' => 'commenced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('owner_agreement_properties')->insert(['branch_id' => $this->branchA, 'owner_agreement_id' => $id, 'property_id' => $propertyId, 'owner_customer_id' => $this->customerA, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function tenantAgreement(int $propertyId, int $ownerId, string $endDate): int
    {
        $id = DB::table('tenant_agreements')->insertGetId([
            'branch_id' => $this->branchA,
            'agreement_no' => 'TA-RENEW-001',
            'tenant_customer_id' => $this->customerA,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => $endDate,
            'total_amount' => 30000,
            'currency_code' => 'AED',
            'payment_count' => 2,
            'payment_mode' => 'cash',
            'status' => 'commenced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_agreement_properties')->insert(['branch_id' => $this->branchA, 'tenant_agreement_id' => $id, 'property_id' => $propertyId, 'source_owner_agreement_id' => $ownerId, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
