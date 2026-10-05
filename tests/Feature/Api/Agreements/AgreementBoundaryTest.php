<?php

namespace Tests\Feature\Api\Agreements;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class AgreementBoundaryTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    private int $propertyId;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->tenantId = $this->customer($this->branchA, 'A-002');
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $this->assignCustomerRole($this->branchA, $this->tenantId, 'tenant');
        $this->propertyId = $this->app['db']->table('properties')->insertGetId([
            'branch_id' => $this->branchA,
            'owner_customer_id' => $this->customerA,
            'property_code' => 'A-PROP-001',
            'property_type' => 'apartment',
            'name' => 'Flat 101',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->apiUser, 'web');
    }

    public function test_owner_and_tenant_agreements_follow_source_coverage_boundary(): void
    {
        $owner = $this->branchRequest()->postJson('/api/v1/owner-agreements', [
            'agreement_no' => 'OA-001',
            'file_no' => 'OWNER-FILE-001',
            'owner_customer_id' => $this->customerA,
            'property_ids' => [$this->propertyId],
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '12000.00',
            'payment_count' => 12,
            'payment_mode' => 'bank_transfer',
            'installments' => $this->agreementInstallments(12),
        ])->assertCreated()->json('data');
        $this->assertSame('AED', $owner['currency_code']);
        $this->assertSame('OWNER-FILE-001', $owner['file_no']);
        $this->branchRequest()->getJson('/api/v1/owner-agreements?search=OWNER-FILE-001')
            ->assertOk()->assertJsonPath('data.0.id', $owner['id']);

        $this->branchRequest()->postJson('/api/v1/tenant-agreements', [
            'agreement_no' => 'TA-BAD',
            'tenant_customer_id' => $this->tenantId,
            'properties' => [[
                'property_id' => $this->propertyId,
                'source_owner_agreement_id' => 999999,
            ]],
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '24000.00',
            'currency_code' => 'AED',
            'payment_count' => 12,
            'payment_mode' => 'cash',
            'installments' => $this->agreementInstallments(12),
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');

        $tenant = $this->branchRequest()->postJson('/api/v1/tenant-agreements', [
            'agreement_no' => 'TA-001',
            'file_no' => 'TENANT-FILE-001',
            'tenant_customer_id' => $this->tenantId,
            'properties' => [[
                'property_id' => $this->propertyId,
                'source_owner_agreement_id' => $owner['id'],
            ]],
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '24000.00',
            'payment_count' => 12,
            'payment_mode' => 'cash',
            'installments' => $this->agreementInstallments(12),
        ])->assertCreated()->json('data');
        $this->assertSame('AED', $tenant['currency_code']);
        $this->assertSame('TENANT-FILE-001', $tenant['file_no']);
        $this->branchRequest()->getJson('/api/v1/tenant-agreements?search=TENANT-FILE-001')
            ->assertOk()->assertJsonPath('data.0.id', $tenant['id']);

        $this->branchRequest()->deleteJson('/api/v1/tenant-agreements/'.$tenant['id'], ['reason' => 'Closed'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertNull($this->app['db']->table('tenant_agreements')->where('id', $tenant['id'])->value('deleted_at'));
    }

    public function test_agreement_from_another_branch_is_not_visible(): void
    {
        $agreementId = $this->app['db']->table('owner_agreements')->insertGetId([
            'branch_id' => $this->branchB,
            'agreement_no' => 'B-OA-001',
            'owner_customer_id' => $this->customerB,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '1000.00',
            'currency_code' => 'AED',
            'payment_count' => 1,
            'payment_mode' => 'cash',
            'status' => 'draft',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->branchRequest()->getJson('/api/v1/owner-agreements/'.$agreementId)
            ->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_custom_installment_dates_and_amounts_are_saved_for_owner_and_tenant(): void
    {
        $owner = $this->branchRequest()->postJson('/api/v1/owner-agreements', [
            'owner_customer_id' => $this->customerA,
            'property_ids' => [$this->propertyId],
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '12000.00',
            'payment_count' => 2,
            'payment_mode' => 'bank_transfer',
            'installments' => [
                ['installment_no' => 1, 'due_date' => '2026-01-15', 'amount' => '5000.00', 'category' => 'rent', 'particulars' => 'January payment'],
                ['installment_no' => 2, 'due_date' => '2026-07-15', 'amount' => '7000.00', 'category' => 'rent', 'particulars' => 'July payment'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.installments.0.due_date', '2026-01-15')
            ->assertJsonPath('data.installments.0.amount', '5000.00')
            ->assertJsonPath('data.installments.1.due_date', '2026-07-15')
            ->assertJsonPath('data.installments.1.amount', '7000.00')
            ->json('data');

        $this->branchRequest()->patchJson('/api/v1/owner-agreements/'.$owner['id'], [
            'installments' => [
                ['installment_no' => 1, 'due_date' => '2026-02-15', 'amount' => '4000.00', 'category' => 'rent', 'particulars' => 'February payment'],
                ['installment_no' => 2, 'due_date' => '2026-08-15', 'amount' => '8000.00', 'category' => 'rent', 'particulars' => 'August payment'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.installments.0.due_date', '2026-02-15')
            ->assertJsonPath('data.installments.0.amount', '4000.00')
            ->assertJsonPath('data.installments.1.due_date', '2026-08-15')
            ->assertJsonPath('data.installments.1.amount', '8000.00');

        $this->branchRequest()->postJson('/api/v1/tenant-agreements', [
            'tenant_customer_id' => $this->tenantId,
            'properties' => [['property_id' => $this->propertyId, 'source_owner_agreement_id' => $owner['id']]],
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '24000.00',
            'payment_count' => 2,
            'payment_mode' => 'cash',
            'installments' => [
                ['installment_no' => 1, 'due_date' => '2026-02-01', 'amount' => '10000.00', 'category' => 'rent', 'particulars' => 'February payment'],
                ['installment_no' => 2, 'due_date' => '2026-08-01', 'amount' => '14000.00', 'category' => 'rent', 'particulars' => 'August payment'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.installments.0.due_date', '2026-02-01')
            ->assertJsonPath('data.installments.0.amount', '10000.00')
            ->assertJsonPath('data.installments.1.due_date', '2026-08-01')
            ->assertJsonPath('data.installments.1.amount', '14000.00');

        $this->branchRequest()->postJson('/api/v1/owner-agreements', [
            'owner_customer_id' => $this->customerA,
            'property_ids' => [$this->propertyId],
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '12000.00',
            'payment_count' => 2,
            'payment_mode' => 'cash',
            'installments' => [
                ['installment_no' => 1, 'due_date' => '2026-01-15', 'amount' => '5000.00', 'category' => 'rent', 'particulars' => 'January payment'],
                ['installment_no' => 2, 'due_date' => '2026-07-15', 'amount' => '6000.00', 'category' => 'rent', 'particulars' => 'July payment'],
            ],
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
    }
}
