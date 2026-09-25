<?php

namespace Tests\Feature\Api\Customers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class CustomerBoundaryTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->actingAs($this->apiUser, 'web');
    }

    public function test_customer_crud_is_branch_scoped_and_soft_deletable(): void
    {
        $created = $this->branchRequest()->postJson('/api/v1/customers', [
            'customer_code' => 'A-002',
            'customer_type' => 'organization',
            'display_name' => 'Branch A Customer',
        ])->assertCreated()->json('data');

        $this->branchRequest()->patchJson('/api/v1/customers/'.$created['id'], [
            'display_name' => 'Updated Customer',
        ])->assertOk()->assertJsonPath('data.display_name', 'Updated Customer');

        $this->branchRequest()->getJson('/api/v1/customers/'.$this->customerB)
            ->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

        $this->branchRequest()->deleteJson('/api/v1/customers/'.$created['id'])->assertNoContent();
        $this->assertDatabaseHas('customers', ['id' => $created['id'], 'status' => 'archived']);
        $this->assertNotNull($this->app['db']->table('customers')->where('id', $created['id'])->value('deleted_at'));
    }

    public function test_customer_mutation_rejects_invalid_fields(): void
    {
        $this->branchRequest()->postJson('/api/v1/customers', [
            'customer_code' => '',
            'customer_type' => 'invalid',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_customer_roles_are_saved_and_returned(): void
    {
        $customer = $this->branchRequest()->postJson('/api/v1/customers', [
            'customer_code' => 'A-003',
            'customer_type' => 'individual',
            'display_name' => 'Owner Tenant',
            'roles' => ['owner', 'tenant'],
        ])->assertCreated()->json('data');

        $this->assertSame(['owner', 'tenant'], $customer['roles']);
        $this->assertDatabaseHas('customer_role_assignments', [
            'branch_id' => $this->branchA,
            'customer_id' => $customer['id'],
            'role' => 'owner',
        ]);
    }

    public function test_owner_and_tenant_codes_are_generated_when_blank(): void
    {
        $owner = $this->branchRequest()->postJson('/api/v1/customers', [
            'customer_type' => 'individual', 'display_name' => 'Generated Owner', 'roles' => ['owner'],
        ])->assertCreated()->json('data');
        $tenant = $this->branchRequest()->postJson('/api/v1/customers', [
            'customer_type' => 'individual', 'display_name' => 'Generated Tenant', 'roles' => ['tenant'],
        ])->assertCreated()->json('data');

        $year = now()->format('Y');
        $this->assertSame("E2E-A-OWN-{$year}-000001", $owner['customer_code']);
        $this->assertSame("E2E-A-TEN-{$year}-000001", $tenant['customer_code']);
    }

    public function test_customer_search_matches_phone_numbers_without_formatting_spaces(): void
    {
        $this->app['db']->table('customers')->where('id', $this->customerA)->update(['phone' => '+971 50 123 4567']);

        $this->branchRequest()->getJson('/api/v1/customers?search=%2B971501234567')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->customerA);
    }

    public function test_vendors_are_customer_records_and_legacy_directory_uses_them(): void
    {
        $vendor = $this->branchRequest()->postJson('/api/v1/customers', [
            'customer_type' => 'organization',
            'display_name' => 'A Maintenance Vendor',
            'phone' => '+971 50 111 2233',
            'roles' => ['vendor'],
        ])->assertCreated()->json('data');

        $this->assertSame('E2E-A-VEN-'.now()->format('Y').'-000001', $vendor['customer_code']);
        $this->assertSame(['vendor'], $vendor['roles']);
        $this->assertDatabaseHas('customer_role_assignments', ['customer_id' => $vendor['id'], 'role' => 'vendor']);
        $this->assertFalse(Schema::hasTable('vendors'));

        $this->branchRequest()->getJson('/api/v1/maintenance/vendors')
            ->assertOk()
            ->assertJsonPath('data.0.id', $vendor['id'])
            ->assertJsonPath('data.0.name', 'A Maintenance Vendor');
    }
}
