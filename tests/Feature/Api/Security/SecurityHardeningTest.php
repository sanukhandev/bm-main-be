<?php

namespace Tests\Feature\Api\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $this->actingAs($this->apiUser, 'web');
    }

    public function test_client_cannot_force_branch_or_derived_status(): void
    {
        $customer = $this->branchRequest()->postJson('/api/v1/customers', [
            'branch_id' => $this->branchB,
            'status' => 'inactive',
            'customer_type' => 'individual',
            'display_name' => 'Scoped Customer',
            'roles' => ['owner'],
        ])->assertCreated()->json('data');

        $this->assertSame($this->branchA, $customer['branch_id']);
        $this->assertSame('active', $customer['status']);

        $property = $this->branchRequest()->postJson('/api/v1/properties', [
            'branch_id' => $this->branchB,
            'status' => 'archived',
            'owner_customer_id' => $this->customerA,
            'property_type' => 'apartment',
            'name' => 'Scoped Property',
        ])->assertCreated()->json('data');

        $this->assertSame($this->branchA, $property['branch_id']);
        $this->assertSame('active', $property['status']);
    }

    public function test_cross_branch_foreign_keys_are_rejected(): void
    {
        $propertyB = DB::table('properties')->insertGetId([
            'branch_id' => $this->branchB,
            'owner_customer_id' => $this->customerB,
            'property_code' => 'B-SEC-001',
            'property_type' => 'apartment',
            'name' => 'Other Branch Property',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->branchRequest()->postJson('/api/v1/properties', [
            'owner_customer_id' => $this->customerB,
            'property_type' => 'apartment',
            'name' => 'Invalid Owner Property',
        ])->assertUnprocessable();

        $this->branchRequest()->postJson('/api/v1/maintenance/work-orders', [
            'property_id' => $propertyB,
            'title' => 'Invalid Branch Work Order',
            'description' => 'Should be rejected',
            'priority' => 'normal',
            'lines' => [],
        ])->assertUnprocessable();
    }

    public function test_agreement_status_and_lifecycle_metadata_cannot_be_forged(): void
    {
        $property = DB::table('properties')->insertGetId([
            'branch_id' => $this->branchA,
            'owner_customer_id' => $this->customerA,
            'property_code' => 'A-SEC-001',
            'property_type' => 'apartment',
            'name' => 'Lifecycle Property',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $agreement = $this->branchRequest()->postJson('/api/v1/owner-agreements', [
            'owner_customer_id' => $this->customerA,
            'property_ids' => [$property],
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '12000.00',
            'currency_code' => 'AED',
            'payment_count' => 12,
            'payment_mode' => 'cash',
        ])->assertCreated()->json('data');

        $this->branchRequest()->patchJson('/api/v1/owner-agreements/'.$agreement['id'], [
            'status' => 'approved',
            'approved_by' => $this->apiUser->id,
            'approved_at' => now()->toIso8601String(),
            'renewed_from_agreement_id' => 999999,
        ])->assertOk();

        $this->assertDatabaseHas('owner_agreements', ['id' => $agreement['id'], 'status' => 'draft']);
    }

    public function test_financial_direction_and_number_are_server_owned(): void
    {
        $agreement = DB::table('tenant_agreements')->insertGetId([
            'branch_id' => $this->branchA,
            'agreement_no' => 'TA-SEC-001',
            'tenant_customer_id' => $this->customerA,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '1000.00',
            'currency_code' => 'AED',
            'payment_count' => 1,
            'payment_mode' => 'cash',
            'status' => 'commenced',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $installment = DB::table('tenant_agreement_installments')->insertGetId([
            'branch_id' => $this->branchA,
            'tenant_agreement_id' => $agreement,
            'installment_no' => 1,
            'due_date' => '2026-01-01',
            'amount' => '1000.00',
            'paid_amount' => '0.00',
            'payment_mode' => 'cash',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->branchRequest()->withHeader('Idempotency-Key', 'security-payment-001')->postJson('/api/v1/tenant-agreements/'.$agreement.'/payments', [
            'installment_id' => $installment,
            'amount' => '100.00',
            'payment_mode' => 'cash',
            'payment_date' => '2026-09-23',
            'direction' => 'outward',
            'document_no' => 'FORGED-DOCUMENT',
            'posted_by' => 999999,
        ])->assertCreated();

        $response->assertJsonPath('data.direction', 'inward');
        $this->assertNotSame('FORGED-DOCUMENT', $response->json('data.document_no'));
    }
}
