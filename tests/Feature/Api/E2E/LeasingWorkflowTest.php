<?php

namespace Tests\Feature\Api\E2E;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class LeasingWorkflowTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    public function test_authenticated_browser_can_complete_branch_scoped_leasing_setup(): void
    {
        $this->seedApiScenario();
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $this->actingAs($this->apiUser, 'web');

        $this->withHeader('Origin', 'http://localhost:4200')
            ->get('/sanctum/csrf-cookie')->assertNoContent();

        $this->withHeader('Origin', 'http://localhost:4200')
            ->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $this->apiUser->email);

        $customer = $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->postJson('/api/v1/customers', [
                'customer_code' => 'E2E-001',
                'customer_type' => 'individual',
                'display_name' => 'E2E Owner',
            ])->assertCreated()->json('data');
        $this->assignCustomerRole($this->branchA, $customer['id'], 'owner');

        $property = $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->postJson('/api/v1/properties', [
                'owner_customer_id' => $customer['id'],
                'property_code' => 'E2E-PROP-001',
                'property_type' => 'apartment',
                'name' => 'E2E Flat',
            ])->assertCreated()->json('data');

        $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->getJson('/api/v1/properties/'.$property['id'])
            ->assertOk()->assertJsonPath('data.property_code', 'E2E-PROP-001');

        $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->getJson('/api/v1/customers/'.$this->customerB)
            ->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }
}
