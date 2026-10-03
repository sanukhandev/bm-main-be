<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Services\Zaakiy\DTOs\ZaakiyConversationContext;
use App\Services\Zaakiy\EntityResolver;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\Skills\Tenant360Skill;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class Tenant360SkillTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_authorized_tenant_360_returns_identity_agreement_property_and_financial_summary(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'tenant');
        $propertyId = $this->property('P-A-204', 'Flat 204');
        $ownerAgreementId = $this->ownerAgreement($propertyId);
        $agreementId = $this->tenantAgreement($propertyId, $ownerAgreementId);
        $installmentId = DB::table('tenant_agreement_installments')->insertGetId([
            'branch_id' => $this->branchA,
            'tenant_agreement_id' => $agreementId,
            'installment_no' => 1,
            'due_date' => now()->subDay()->toDateString(),
            'amount' => 15000,
            'paid_amount' => 2500,
            'payment_mode' => 'cheque',
            'status' => 'partially_paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transactionId = DB::table('account_transactions')->insertGetId([
            'branch_id' => $this->branchA,
            'document_no' => 'IR-A-001',
            'direction' => 'inward',
            'transaction_date' => now()->toDateString(),
            'payment_mode' => 'cheque',
            'amount' => 2500,
            'party_customer_id' => $this->customerA,
            'source_type' => 'tenant_agreement',
            'source_id' => $agreementId,
            'cheque_status' => 'received',
            'status' => 'posted',
            'created_by' => $this->apiUser->id,
            'posted_by' => $this->apiUser->id,
            'posted_at' => now(),
            'idempotency_key' => 'tenant-360-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('account_transaction_allocations')->insert([
            'branch_id' => $this->branchA,
            'account_transaction_id' => $transactionId,
            'tenant_agreement_installment_id' => $installmentId,
            'amount' => 2500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->runSkill('Show tenant A-001.');

        $this->assertSame('tenant_360', $result->intent);
        $this->assertSame('A-001', $result->records[0]['customer_code']);
        $this->assertSame('12500.00', $result->summaryMetrics['outstanding_receivable']);
        $this->assertSame('12500.00', $result->summaryMetrics['overdue_receivable']);
        $this->assertSame(1, $result->summaryMetrics['pending_cheque_count']);
        $this->assertSame('TEN-A-001', $result->sources[1]['label']);
        $this->assertSame('/app/customers/'.$this->customerA, $result->navigation[0]['route']);
    }

    public function test_financial_data_is_removed_without_accounts_permission(): void
    {
        $roleId = DB::table('roles')->where('key', 'branch_admin')->value('id');
        $permissionId = DB::table('permissions')->where('key', 'accounts.view')->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        $this->assignCustomerRole($this->branchA, $this->customerA, 'tenant');

        $result = $this->runSkill('Tell me about A-001.');

        $this->assertArrayNotHasKey('outstanding_receivable', $result->summaryMetrics);
        $this->assertSame('FINANCIAL_DATA_RESTRICTED', $result->warnings[0]['code']);
        $this->assertFalse($result->meta['financial_included']);
    }

    public function test_owner_only_customer_is_not_treated_as_tenant(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');

        $result = $this->runSkill('Show tenant A-001.');

        $this->assertSame('TENANT_NOT_FOUND', $result->warnings[0]['code']);
        $this->assertSame([], $result->records);
    }

    public function test_cross_branch_tenant_is_not_exposed(): void
    {
        $this->assignCustomerRole($this->branchB, $this->customerB, 'tenant');
        DB::table('customers')->where('id', $this->customerB)->update(['customer_code' => 'TEN-B-0098']);

        $result = $this->runSkill('Show tenant TEN-B-0098.');

        $this->assertSame('TENANT_NOT_FOUND', $result->warnings[0]['code']);
        $this->assertSame([], $result->records);
    }

    public function test_ambiguous_tenant_name_is_not_guessed(): void
    {
        $first = $this->makeCustomer('TEN-A-1001', 'Mohammed Ali');
        $second = $this->makeCustomer('TEN-A-1002', 'Mohammed Ali');
        $this->assignCustomerRole($this->branchA, $first, 'tenant');
        $this->assignCustomerRole($this->branchA, $second, 'tenant');

        $result = $this->runSkill('Tell me about Mohammed Ali.');

        $this->assertSame('TENANT_AMBIGUOUS', $result->warnings[0]['code']);
        $this->assertCount(2, $result->records);
    }

    public function test_tampered_tenant_context_is_reauthorized_for_the_active_branch(): void
    {
        $this->assignCustomerRole($this->branchB, $this->customerB, 'tenant');
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));
        $execution = new ZaakiyExecutionContext(
            $this->apiUser,
            $branch,
            new IntentFrame('tenant_360', ['tenant_360'], 'detail'),
            now()->toIso8601String(),
        );
        $context = new ZaakiyConversationContext(
            entities: [['type' => 'tenant', 'id' => $this->customerB, 'label' => 'spoofed']],
            branchContext: ['branch_id' => $this->branchA],
        );

        $authorized = app(EntityResolver::class)->reauthorize($context, $execution);

        $this->assertSame([], $authorized->entities);
    }

    private function runSkill(string $message)
    {
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));

        return app(Tenant360Skill::class)->execute(new ZaakiyExecutionContext(
            $this->apiUser,
            $branch,
            new IntentFrame('tenant_360', ['tenant_360'], 'detail', question: $message),
            now()->toIso8601String(),
        ));
    }

    private function makeCustomer(string $code, string $name): int
    {
        return DB::table('customers')->insertGetId([
            'branch_id' => $this->branchA,
            'customer_code' => $code,
            'customer_type' => 'individual',
            'display_name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    private function ownerAgreement(int $propertyId): int
    {
        $id = DB::table('owner_agreements')->insertGetId([
            'branch_id' => $this->branchA,
            'agreement_no' => 'OA-A-001',
            'owner_customer_id' => $this->customerA,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
            'total_amount' => 60000,
            'currency_code' => 'AED',
            'payment_count' => 2,
            'payment_frequency' => 'monthly',
            'payment_mode' => 'bank_transfer',
            'status' => 'commenced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('owner_agreement_properties')->insert([
            'branch_id' => $this->branchA,
            'owner_agreement_id' => $id,
            'property_id' => $propertyId,
            'owner_customer_id' => $this->customerA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function tenantAgreement(int $propertyId, int $ownerAgreementId): int
    {
        $id = DB::table('tenant_agreements')->insertGetId([
            'branch_id' => $this->branchA,
            'agreement_no' => 'TEN-A-001',
            'tenant_customer_id' => $this->customerA,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'total_amount' => 30000,
            'currency_code' => 'AED',
            'payment_count' => 2,
            'payment_frequency' => 'monthly',
            'payment_mode' => 'bank_transfer',
            'status' => 'commenced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_agreement_properties')->insert([
            'branch_id' => $this->branchA,
            'tenant_agreement_id' => $id,
            'property_id' => $propertyId,
            'source_owner_agreement_id' => $ownerAgreementId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
