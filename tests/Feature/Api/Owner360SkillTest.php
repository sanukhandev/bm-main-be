<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Services\Zaakiy\DTOs\ZaakiyConversationContext;
use App\Services\Zaakiy\EntityResolver;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\Skills\Owner360Skill;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class Owner360SkillTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_authorized_owner_360_returns_portfolio_agreement_and_outward_financial_summary(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $propertyId = $this->property('P-A-204', 'Flat 204');
        $agreementId = $this->ownerAgreement($propertyId);
        $this->tenantAgreement($propertyId, $agreementId);
        $installmentId = DB::table('owner_agreement_installments')->insertGetId([
            'branch_id' => $this->branchA,
            'owner_agreement_id' => $agreementId,
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
            'document_no' => 'OR-A-001',
            'direction' => 'outward',
            'transaction_date' => now()->toDateString(),
            'payment_mode' => 'cheque',
            'amount' => 2500,
            'party_customer_id' => $this->customerA,
            'source_type' => 'owner_agreement',
            'source_id' => $agreementId,
            'cheque_status' => 'received',
            'status' => 'posted',
            'created_by' => $this->apiUser->id,
            'posted_by' => $this->apiUser->id,
            'posted_at' => now(),
            'idempotency_key' => 'owner-360-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('account_transaction_allocations')->insert([
            'branch_id' => $this->branchA,
            'account_transaction_id' => $transactionId,
            'owner_agreement_installment_id' => $installmentId,
            'amount' => 2500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->runSkill('Show owner A-001.');

        $this->assertSame('owner_360', $result->intent);
        $this->assertSame('A-001', $result->records[0]['customer_code']);
        $this->assertSame(1, $result->summaryMetrics['property_count']);
        $this->assertSame(1, $result->summaryMetrics['occupied_property_count']);
        $this->assertSame('12500.00', $result->summaryMetrics['owner_payable']);
        $this->assertSame('12500.00', $result->summaryMetrics['overdue_owner_payable']);
        $this->assertSame(1, $result->summaryMetrics['pending_owner_cheque_count']);
        $this->assertContains('OA-A-001', array_column($result->sources, 'label'));
    }

    public function test_financial_data_is_removed_without_accounts_permission(): void
    {
        $roleId = DB::table('roles')->where('key', 'branch_admin')->value('id');
        $permissionId = DB::table('permissions')->where('key', 'accounts.view')->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $this->property('P-A-205', 'Flat 205');

        $result = $this->runSkill('Tell me about owner A-001.');

        $this->assertArrayNotHasKey('owner_payable', $result->summaryMetrics);
        $this->assertSame('FINANCIAL_DATA_RESTRICTED', $result->warnings[0]['code']);
        $this->assertFalse($result->meta['financial_included']);
    }

    public function test_tenant_only_customer_is_not_treated_as_owner(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'tenant');

        $result = $this->runSkill('Show owner A-001.');

        $this->assertSame('OWNER_NOT_FOUND', $result->warnings[0]['code']);
        $this->assertSame([], $result->records);
    }

    public function test_cross_branch_owner_is_not_exposed(): void
    {
        $this->assignCustomerRole($this->branchB, $this->customerB, 'owner');
        DB::table('customers')->where('id', $this->customerB)->update(['customer_code' => 'OWN-B-0098']);

        $result = $this->runSkill('Show owner OWN-B-0098.');

        $this->assertSame('OWNER_NOT_FOUND', $result->warnings[0]['code']);
        $this->assertSame([], $result->records);
    }

    public function test_ambiguous_owner_name_is_not_guessed(): void
    {
        $first = $this->makeCustomer('OWN-A-1001', 'Mohammed Khan');
        $second = $this->makeCustomer('OWN-A-1002', 'Mohammed Khan');
        $this->assignCustomerRole($this->branchA, $first, 'owner');
        $this->assignCustomerRole($this->branchA, $second, 'owner');

        $result = $this->runSkill('Tell me about owner Mohammed Khan.');

        $this->assertSame('OWNER_AMBIGUOUS', $result->warnings[0]['code']);
        $this->assertCount(2, $result->records);
    }

    public function test_owner_context_is_reauthorized_for_the_active_branch(): void
    {
        $this->assignCustomerRole($this->branchB, $this->customerB, 'owner');
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));
        $execution = new ZaakiyExecutionContext($this->apiUser, $branch, new IntentFrame('owner_360', ['owner_360'], 'detail'), now()->toIso8601String());
        $context = new ZaakiyConversationContext(entities: [['type' => 'owner', 'id' => $this->customerB, 'label' => 'spoofed']], branchContext: ['branch_id' => $this->branchA]);

        $authorized = app(EntityResolver::class)->reauthorize($context, $execution);

        $this->assertSame([], $authorized->entities);
    }

    public function test_owner_follow_up_reuses_the_reauthorized_owner_context(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));
        $context = new ZaakiyConversationContext(
            intent: 'owner_360',
            entities: [['type' => 'owner', 'id' => $this->customerA, 'label' => 'A-001']],
            branchContext: ['branch_id' => $this->branchA],
        );

        $result = app(Owner360Skill::class)->execute(new ZaakiyExecutionContext(
            $this->apiUser,
            $branch,
            new IntentFrame('owner_360', ['owner_360'], 'detail', question: 'Which properties are vacant?'),
            now()->toIso8601String(),
            $context,
        ));

        $this->assertSame('A-001', $result->records[0]['customer_code']);
    }

    private function runSkill(string $message)
    {
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));

        return app(Owner360Skill::class)->execute(new ZaakiyExecutionContext($this->apiUser, $branch, new IntentFrame('owner_360', ['owner_360'], 'detail', question: $message), now()->toIso8601String()));
    }

    private function property(string $code, string $name): int
    {
        return DB::table('properties')->insertGetId(['branch_id' => $this->branchA, 'owner_customer_id' => $this->customerA, 'property_code' => $code, 'unit_number' => '204', 'property_type' => 'apartment', 'name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function ownerAgreement(int $propertyId): int
    {
        $id = DB::table('owner_agreements')->insertGetId(['branch_id' => $this->branchA, 'agreement_no' => 'OA-A-001', 'owner_customer_id' => $this->customerA, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonths(6)->toDateString(), 'total_amount' => 60000, 'currency_code' => 'AED', 'payment_count' => 2, 'payment_frequency' => 'monthly', 'payment_mode' => 'bank_transfer', 'status' => 'commenced', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('owner_agreement_properties')->insert(['branch_id' => $this->branchA, 'owner_agreement_id' => $id, 'property_id' => $propertyId, 'owner_customer_id' => $this->customerA, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function tenantAgreement(int $propertyId, int $ownerAgreementId): int
    {
        $id = DB::table('tenant_agreements')->insertGetId(['branch_id' => $this->branchA, 'agreement_no' => 'TA-A-001', 'tenant_customer_id' => $this->customerA, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonths(3)->toDateString(), 'total_amount' => 30000, 'currency_code' => 'AED', 'payment_count' => 2, 'payment_frequency' => 'monthly', 'payment_mode' => 'bank_transfer', 'status' => 'commenced', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_agreement_properties')->insert(['branch_id' => $this->branchA, 'tenant_agreement_id' => $id, 'property_id' => $propertyId, 'source_owner_agreement_id' => $ownerAgreementId, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function makeCustomer(string $code, string $name): int
    {
        return DB::table('customers')->insertGetId(['branch_id' => $this->branchA, 'customer_code' => $code, 'customer_type' => 'individual', 'display_name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }
}
