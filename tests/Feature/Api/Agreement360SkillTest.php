<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Services\Zaakiy\DTOs\ZaakiyConversationContext;
use App\Services\Zaakiy\EntityResolver;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\Skills\Agreement360Skill;
use App\Services\Zaakiy\ZaakiyConversationContextResolver;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class Agreement360SkillTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_tenant_agreement_returns_inward_financial_summary(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'tenant');
        $propertyId = $this->property('P-A-204', 'Flat 204');
        $ownerAgreementId = $this->ownerAgreement($propertyId);
        $agreementId = $this->tenantAgreement($propertyId, $ownerAgreementId);
        $installmentId = $this->installment('tenant', $agreementId, 15000, 2500);
        $this->payment($agreementId, $installmentId, 'tenant', 'inward', 'IR-A-001');

        $result = $this->runSkill('Show TA-A-001.');

        $this->assertSame('agreement_360', $result->intent);
        $this->assertSame('tenant_agreement', $result->meta['agreement_type']);
        $this->assertSame('inward', $result->meta['financial_direction']);
        $this->assertSame('12500.00', $result->summaryMetrics['outstanding']);
        $this->assertSame('12500.00', $result->summaryMetrics['overdue']);
        $this->assertSame('inward', $this->record($result->records, 'recent_payment')['direction']);
        $this->assertContains('A-001', array_column($result->records, 'customer_code'));
        $this->assertSame('P-A-204', $this->record($result->records, 'property')['property_code']);
    }

    public function test_owner_agreement_returns_outward_financial_summary(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $propertyId = $this->property('P-A-205', 'Flat 205');
        $agreementId = $this->ownerAgreement($propertyId);
        $installmentId = $this->installment('owner', $agreementId, 20000, 5000);
        $this->payment($agreementId, $installmentId, 'owner', 'outward', 'OR-A-001');

        $result = $this->runSkill('Tell me about OA-A-001.');

        $this->assertSame('owner_agreement', $result->meta['agreement_type']);
        $this->assertSame('outward', $result->meta['financial_direction']);
        $this->assertSame('15000.00', $result->summaryMetrics['outstanding']);
        $this->assertSame('15000.00', $result->summaryMetrics['overdue']);
        $this->assertSame('outward', $this->record($result->records, 'recent_payment')['direction']);
        $this->assertContains('purchase_receipt', array_column($result->records, 'receipt_type'));
    }

    public function test_financial_data_is_removed_without_accounts_permission(): void
    {
        $roleId = DB::table('roles')->where('key', 'branch_admin')->value('id');
        $permissionId = DB::table('permissions')->where('key', 'accounts.view')->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $this->ownerAgreement($this->property('P-A-206', 'Flat 206'));

        $result = $this->runSkill('Show OA-A-001.');

        $this->assertArrayNotHasKey('outstanding', $result->summaryMetrics);
        $this->assertArrayNotHasKey('total_amount', $result->records[0]);
        $this->assertSame('FINANCIAL_DATA_RESTRICTED', $result->warnings[0]['code']);
        $this->assertFalse($result->meta['financial_included']);
    }

    public function test_cross_branch_agreement_is_not_exposed(): void
    {
        $id = DB::table('owner_agreements')->insertGetId(['branch_id' => $this->branchB, 'agreement_no' => 'OA-B-001', 'owner_customer_id' => $this->customerB, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonth()->toDateString(), 'total_amount' => 1000, 'currency_code' => 'AED', 'payment_count' => 1, 'payment_mode' => 'cash', 'status' => 'commenced', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertGreaterThan(0, $id);

        $result = $this->runSkill('Show OA-B-001.');

        $this->assertSame('AGREEMENT_NOT_FOUND', $result->warnings[0]['code']);
        $this->assertSame([], $result->records);
    }

    public function test_context_tampering_cannot_reuse_cross_branch_agreement(): void
    {
        $id = DB::table('owner_agreements')->insertGetId(['branch_id' => $this->branchB, 'agreement_no' => 'OA-B-002', 'owner_customer_id' => $this->customerB, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonth()->toDateString(), 'total_amount' => 1000, 'currency_code' => 'AED', 'payment_count' => 1, 'payment_mode' => 'cash', 'status' => 'commenced', 'created_at' => now(), 'updated_at' => now()]);
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));
        $execution = new ZaakiyExecutionContext($this->apiUser, $branch, new IntentFrame('agreement_360', ['agreement_360'], 'detail'), now()->toIso8601String());
        $context = new ZaakiyConversationContext(intent: 'agreement_360', entities: [['type' => 'owner_agreement', 'id' => $id, 'label' => 'OA-B-002']], branchContext: ['branch_id' => $this->branchA]);

        $authorized = app(EntityResolver::class)->reauthorize($context, $execution);

        $this->assertSame([], $authorized->entities);
    }

    public function test_agreement_context_can_route_to_property_follow_up(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'tenant');
        $propertyId = $this->property('P-A-207', 'Flat 207');
        $ownerAgreementId = $this->ownerAgreement($propertyId);
        $result = $this->runSkill('Show TA-A-001.', $this->tenantAgreement($propertyId, $ownerAgreementId));
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));
        $context = app(ZaakiyConversationContextResolver::class)->complete(new ZaakiyConversationContext, new IntentFrame('agreement_360', ['agreement_360'], 'detail'), [$result], $branch);
        $intent = app(ZaakiyConversationContextResolver::class)->applyToIntent(new IntentFrame('property.occupancy', ['properties'], 'aggregate', question: 'Tell me about the property.'), $context, 'Tell me about the property.');

        $this->assertSame(['property_360'], $intent->modules);
    }

    private function runSkill(string $message, ?int $agreementId = null)
    {
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));
        $conversation = $agreementId ? new ZaakiyConversationContext(intent: 'agreement_360', entities: [['type' => 'tenant_agreement', 'id' => $agreementId, 'label' => 'TA-A-001']], branchContext: ['branch_id' => $this->branchA]) : null;

        return app(Agreement360Skill::class)->execute(new ZaakiyExecutionContext($this->apiUser, $branch, new IntentFrame('agreement_360', ['agreement_360'], 'detail', question: $message), now()->toIso8601String(), $conversation));
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

    private function installment(string $type, int $agreementId, int $amount, int $paid): int
    {
        $table = $type.'_agreement_installments';
        $foreign = $type.'_agreement_id';

        return DB::table($table)->insertGetId(['branch_id' => $this->branchA, $foreign => $agreementId, 'installment_no' => 1, 'due_date' => now()->subDay()->toDateString(), 'amount' => $amount, 'paid_amount' => $paid, 'payment_mode' => 'cheque', 'status' => 'partially_paid', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payment(int $agreementId, int $installmentId, string $type, string $direction, string $documentNo): void
    {
        $transactionId = DB::table('account_transactions')->insertGetId(['branch_id' => $this->branchA, 'document_no' => $documentNo, 'direction' => $direction, 'transaction_date' => now()->toDateString(), 'payment_mode' => 'cheque', 'amount' => 2500, 'party_customer_id' => $this->customerA, 'source_type' => $type.'_agreement', 'source_id' => $agreementId, 'cheque_status' => 'received', 'status' => 'posted', 'created_by' => $this->apiUser->id, 'posted_by' => $this->apiUser->id, 'posted_at' => now(), 'idempotency_key' => strtolower($documentNo), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('account_transaction_allocations')->insert(['branch_id' => $this->branchA, 'account_transaction_id' => $transactionId, $type.'_agreement_installment_id' => $installmentId, 'amount' => 2500, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function record(array $records, string $type): array
    {
        return collect($records)->firstWhere('type', $type);
    }
}
