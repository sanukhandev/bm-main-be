<?php

namespace Tests\Feature\Api\Customers;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
