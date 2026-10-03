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

    public function test_organization_identity_field_accepts_and_formats_trade_license_number(): void
    {
        $customer = $this->branchRequest()->postJson('/api/v1/customers', [
            'customer_type' => 'organization',
            'display_name' => 'Trade Licence Organization',
            'identity_no' => ' ab 12-34 ',
            'tax_registration_no' => null,
        ])->assertCreated()->json('data');

        $this->assertSame('AB12-34', $customer['identity_no']);
        $this->assertNull($customer['tax_registration_no']);

        $this->branchRequest()->patchJson('/api/v1/customers/'.$customer['id'], [
            'identity_no' => 'bad trade licence value!',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
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

    public function test_phone_numbers_support_multiple_typed_values_and_legacy_phone_fallback(): void
    {
        $this->app['db']->table('customers')->where('id', $this->customerA)->update(['phone' => '+971 50 000 0001']);
        $this->branchRequest()->getJson('/api/v1/customers/'.$this->customerA)
            ->assertOk()
            ->assertJsonPath('data.phone_numbers.0.type', 'contact')
            ->assertJsonPath('data.phone_numbers.0.number', '+971 50 000 0001');

        $customer = $this->branchRequest()->postJson('/api/v1/customers', [
            'customer_type' => 'individual',
            'display_name' => 'Multiple Phones',
            'phone_numbers' => [
                ['type' => 'contact', 'number' => '+971 50 000 0002'],
                ['type' => 'whatsapp', 'number' => '+971 50 000 0003'],
                ['type' => 'landline', 'number' => '+971 4 000 0004'],
            ],
        ])->assertCreated()->json('data');

        $this->assertCount(3, $customer['phone_numbers']);
        $this->assertSame('+971 50 000 0002', $customer['phone']);
        $this->assertSame(
            '+971 50 000 0002',
            json_decode($this->app['db']->table('customers')->where('id', $customer['id'])->value('phone_numbers_json'), true)[0]['number'],
        );

        $this->branchRequest()->getJson('/api/v1/customers?search=%2B971500000003')
            ->assertOk()
            ->assertJsonFragment(['id' => $customer['id']]);

        $this->branchRequest()->patchJson('/api/v1/customers/'.$customer['id'], [
            'phone' => '+971 50 000 0099',
        ])->assertOk()
            ->assertJsonPath('data.phone', '+971 50 000 0099')
            ->assertJsonPath('data.phone_numbers.0.number', '+971 50 000 0099')
            ->assertJsonPath('data.phone_numbers.1.number', '+971 50 000 0003');
    }

    public function test_owner_can_have_optional_representative_but_tenant_cannot(): void
    {
        $owner = $this->branchRequest()->postJson('/api/v1/customers', [
            'customer_type' => 'individual',
            'display_name' => 'Owner With Representative',
            'roles' => ['owner'],
            'representative' => [
                'name' => 'Owner Son',
                'relationship' => 'son',
                'phone' => '+971 50 000 0005',
                'identity_no' => '784-1990-1234567-1',
            ],
        ])->assertCreated()->json('data');

        $this->assertSame('Owner Son', $owner['representative']['name']);
        $this->assertSame('784-1990-1234567-1', $owner['representative']['identity_no']);

        $this->branchRequest()->patchJson('/api/v1/customers/'.$owner['id'], [
            'roles' => ['tenant'],
        ])->assertOk()->assertJsonPath('data.representative', null);

        $this->branchRequest()->postJson('/api/v1/customers', [
            'customer_type' => 'individual',
            'display_name' => 'Tenant With Representative',
            'roles' => ['tenant'],
            'representative' => [
                'name' => 'Tenant Relative',
                'identity_no' => '784-1990-1234567-1',
            ],
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
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
