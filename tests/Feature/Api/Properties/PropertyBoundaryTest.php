<?php

namespace Tests\Feature\Api\Properties;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class PropertyBoundaryTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    public static function propertyTypes(): array
    {
        return [
            ['apartment'],
            ['villa'],
            ['shop'],
            ['office'],
            ['space'],
            ['labor_camp'],
            ['warehouse'],
            ['land'],
        ];
    }

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

    #[DataProvider('propertyTypes')]
    public function test_all_supported_property_types_are_accepted_on_create(string $propertyType): void
    {
        $property = $this->branchRequest()->postJson('/api/v1/properties', [
            'owner_customer_id' => $this->customerA,
            'property_code' => 'TYPE-'.strtoupper($propertyType),
            'property_type' => $propertyType,
            'name' => 'Supported '.$propertyType,
        ])->assertCreated()->json('data');

        $this->assertSame($propertyType, $property['property_type']);
        $this->assertDatabaseHas('properties', ['id' => $property['id'], 'property_type' => $propertyType]);
    }

    #[DataProvider('propertyTypes')]
    public function test_all_supported_property_types_are_accepted_on_update(string $propertyType): void
    {
        $property = $this->branchRequest()->postJson('/api/v1/properties', [
            'owner_customer_id' => $this->customerA,
            'property_code' => 'UPDATE-'.strtoupper($propertyType),
            'property_type' => 'apartment',
            'name' => 'Update target',
        ])->json('data');

        $this->branchRequest()->patchJson('/api/v1/properties/'.$property['id'], [
            'property_type' => $propertyType,
        ])->assertOk()->assertJsonPath('data.property_type', $propertyType);
    }

    public function test_invalid_property_types_are_rejected_on_create_and_update(): void
    {
        $this->branchRequest()->postJson('/api/v1/properties', [
            'owner_customer_id' => $this->customerA,
            'property_code' => 'INVALID-001',
            'property_type' => 'random_type',
            'name' => 'Invalid property',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');

        $property = $this->branchRequest()->postJson('/api/v1/properties', [
            'owner_customer_id' => $this->customerA,
            'property_code' => 'VALID-001',
            'property_type' => 'apartment',
            'name' => 'Valid property',
        ])->json('data');

        $this->branchRequest()->patchJson('/api/v1/properties/'.$property['id'], [
            'property_type' => 'unit',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_property_type_filter_uses_the_same_enum_validation(): void
    {
        $this->branchRequest()->getJson('/api/v1/properties?property_type=apartment')->assertOk();
        $this->branchRequest()->getJson('/api/v1/properties?property_type=xyz')->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_property_code_is_generated_from_branch_emirate_building_unit_and_type(): void
    {
        $property = $this->branchRequest()->postJson('/api/v1/properties', [
            'owner_customer_id' => $this->customerA,
            'state_or_emirate' => 'Dubai',
            'building_name' => 'Al Madeena Tower',
            'unit_number' => '101',
            'property_type' => 'apartment',
            'name' => 'Flat 101',
        ])->assertCreated()->json('data');

        $this->assertSame('E2E-A-DXB-AL-MADEENA-TOWER-101-APT', $property['property_code']);
    }
}
