<?php

namespace Tests\Feature\Api\Properties;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class PropertyBoundaryTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $this->actingAs($this->apiUser, 'web');
    }

    public function test_property_requires_an_owner_in_the_current_branch(): void
    {
        $this->branchRequest()->postJson('/api/v1/properties', [
            'owner_customer_id' => $this->customerB,
            'property_code' => 'B-001',
            'property_type' => 'apartment',
            'name' => 'Wrong Branch Property',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_property_is_hidden_across_branch_boundaries(): void
    {
        $propertyId = $this->app['db']->table('properties')->insertGetId([
            'branch_id' => $this->branchB,
            'owner_customer_id' => $this->customerB,
            'property_code' => 'B-001',
            'property_type' => 'shop',
            'name' => 'Branch B Shop',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->branchRequest()->getJson('/api/v1/properties/'.$propertyId)
            ->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_property_delete_is_soft_delete_only(): void
    {
        $property = $this->branchRequest()->postJson('/api/v1/properties', [
            'owner_customer_id' => $this->customerA,
            'property_code' => 'A-001',
            'property_type' => 'apartment',
            'name' => 'Flat 101',
        ])->assertCreated()->json('data');

        $this->branchRequest()->deleteJson('/api/v1/properties/'.$property['id'])->assertNoContent();
        $this->assertDatabaseHas('properties', ['id' => $property['id'], 'status' => 'archived']);
        $this->assertNotNull($this->app['db']->table('properties')->where('id', $property['id'])->value('deleted_at'));
    }
}
