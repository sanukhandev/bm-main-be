<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\Skills\AgreementRiskSkill;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class AgreementRiskSkillTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_attention_signals_are_deterministic_and_permission_aware(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $this->assignCustomerRole($this->branchA, $this->customerA, 'tenant');
        $propertyId = $this->property('P-A-RISK', 'Flat Risk');
        $ownerId = $this->ownerAgreement($propertyId, now()->addDays(14)->toDateString());
        $tenantId = $this->tenantAgreement($propertyId, $ownerId, now()->addDays(30)->toDateString());
        $installmentId = DB::table('tenant_agreement_installments')->insertGetId(['branch_id' => $this->branchA, 'tenant_agreement_id' => $tenantId, 'installment_no' => 1, 'due_date' => now()->subDay()->toDateString(), 'amount' => 10000, 'paid_amount' => 4000, 'payment_mode' => 'cheque', 'status' => 'partially_paid', 'created_at' => now(), 'updated_at' => now()]);
        $this->bouncedPayment($tenantId, $installmentId);

        $result = $this->runSkill('Which agreements need attention?');
        $tenant = collect($result->records)->firstWhere('agreement_no', 'TA-RISK-001');

        $this->assertNotNull($tenant);
        $this->assertContains('EXPIRING_SOON', $tenant['signals']);
        $this->assertContains('EXPIRING_WITH_OUTSTANDING', $tenant['signals']);
        $this->assertContains('EXPIRING_WITH_OVERDUE', $tenant['signals']);
        $this->assertContains('BOUNCED_CHEQUE', $tenant['signals']);
        $this->assertContains('OWNER_COVERAGE_BEFORE_TENANT_END', $tenant['signals']);
        $this->assertSame('6000.00', $tenant['outstanding']);
        $this->assertSame('6000.00', $tenant['overdue']);
        $this->assertSame('inward', $tenant['financial_direction']);
        $this->assertGreaterThanOrEqual(1, $result->summaryMetrics['agreement_count_needing_attention']);
    }

    public function test_without_accounts_permission_keeps_lifecycle_signals_only(): void
    {
        $roleId = DB::table('roles')->where('key', 'branch_admin')->value('id');
        $permissionId = DB::table('permissions')->where('key', 'accounts.view')->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $propertyId = $this->property('P-A-NOFIN', 'Flat No Finance');
        $this->ownerAgreement($propertyId, now()->addDays(5)->toDateString());

        $result = $this->runSkill('Which owner agreements expire soon?');

        $this->assertFalse($result->meta['financial_included']);
        $this->assertArrayNotHasKey('total_outstanding_amount', $result->summaryMetrics);
        $this->assertArrayNotHasKey('outstanding', $result->records[0] ?? []);
        $this->assertContains('EXPIRING_SOON', $result->records[0]['signals'] ?? []);
        $this->assertSame('FINANCIAL_DATA_RESTRICTED', $result->warnings[0]['code']);
    }

    public function test_cancelled_and_other_branch_agreements_are_excluded(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $propertyId = $this->property('P-A-CANCEL', 'Flat Cancelled');
        $cancelledId = $this->ownerAgreement($propertyId, now()->addDays(3)->toDateString(), 'cancelled', 'OA-CANCELLED');
        $this->assertGreaterThan(0, $cancelledId);
        DB::table('owner_agreements')->insert(['branch_id' => $this->branchB, 'agreement_no' => 'OA-B-RISK', 'owner_customer_id' => $this->customerB, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(), 'total_amount' => 1000, 'currency_code' => 'AED', 'payment_count' => 1, 'payment_mode' => 'cash', 'status' => 'commenced', 'created_at' => now(), 'updated_at' => now()]);

        $result = $this->runSkill('Which owner agreements expire soon?');

        $this->assertSame([], $result->records);
    }

    private function runSkill(string $message): ZaakiySkillResult
    {
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));

        return app(AgreementRiskSkill::class)->execute(new ZaakiyExecutionContext($this->apiUser, $branch, new IntentFrame('agreement_risk', ['agreement_risk'], 'search', question: $message), now()->toIso8601String()));
    }

    private function property(string $code, string $name): int
    {
        return DB::table('properties')->insertGetId(['branch_id' => $this->branchA, 'owner_customer_id' => $this->customerA, 'property_code' => $code, 'unit_number' => '204', 'property_type' => 'apartment', 'name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function ownerAgreement(int $propertyId, string $endDate, string $status = 'commenced', string $number = 'OA-RISK-001'): int
    {
        $id = DB::table('owner_agreements')->insertGetId(['branch_id' => $this->branchA, 'agreement_no' => $number, 'owner_customer_id' => $this->customerA, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => $endDate, 'total_amount' => 60000, 'currency_code' => 'AED', 'payment_count' => 2, 'payment_frequency' => 'monthly', 'payment_mode' => 'bank_transfer', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('owner_agreement_properties')->insert(['branch_id' => $this->branchA, 'owner_agreement_id' => $id, 'property_id' => $propertyId, 'owner_customer_id' => $this->customerA, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function tenantAgreement(int $propertyId, int $ownerId, string $endDate): int
    {
        $id = DB::table('tenant_agreements')->insertGetId(['branch_id' => $this->branchA, 'agreement_no' => 'TA-RISK-001', 'tenant_customer_id' => $this->customerA, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => $endDate, 'total_amount' => 30000, 'currency_code' => 'AED', 'payment_count' => 2, 'payment_frequency' => 'monthly', 'payment_mode' => 'cheque', 'status' => 'commenced', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_agreement_properties')->insert(['branch_id' => $this->branchA, 'tenant_agreement_id' => $id, 'property_id' => $propertyId, 'source_owner_agreement_id' => $ownerId, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function bouncedPayment(int $agreementId, int $installmentId): void
    {
        $transactionId = DB::table('account_transactions')->insertGetId(['branch_id' => $this->branchA, 'document_no' => 'IR-RISK-001', 'direction' => 'inward', 'transaction_date' => now()->toDateString(), 'payment_mode' => 'cheque', 'amount' => 4000, 'party_customer_id' => $this->customerA, 'source_type' => 'tenant_agreement', 'source_id' => $agreementId, 'cheque_status' => 'bounced', 'cheque_date' => now()->subDay()->toDateString(), 'status' => 'posted', 'created_by' => $this->apiUser->id, 'posted_by' => $this->apiUser->id, 'posted_at' => now(), 'idempotency_key' => 'ir-risk-001', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('account_transaction_allocations')->insert(['branch_id' => $this->branchA, 'account_transaction_id' => $transactionId, 'tenant_agreement_installment_id' => $installmentId, 'amount' => 4000, 'created_at' => now(), 'updated_at' => now()]);
    }
}
