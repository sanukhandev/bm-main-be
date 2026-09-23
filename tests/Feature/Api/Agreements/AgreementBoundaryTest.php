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
            'owner_customer_id' => $this->customerA,
            'property_ids' => [$this->propertyId],
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '12000.00',
            'currency_code' => 'AED',
            'payment_count' => 12,
            'payment_mode' => 'bank_transfer',
        ])->assertCreated()->json('data');

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
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');

        $tenant = $this->branchRequest()->postJson('/api/v1/tenant-agreements', [
            'agreement_no' => 'TA-001',
            'tenant_customer_id' => $this->tenantId,
            'properties' => [[
                'property_id' => $this->propertyId,
                'source_owner_agreement_id' => $owner['id'],
            ]],
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '24000.00',
            'currency_code' => 'AED',
            'payment_count' => 12,
            'payment_mode' => 'cash',
        ])->assertCreated()->json('data');

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
}
