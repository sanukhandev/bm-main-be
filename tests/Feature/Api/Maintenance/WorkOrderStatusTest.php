<?php

namespace Tests\Feature\Api\Maintenance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class WorkOrderStatusTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->actingAs($this->apiUser, 'web');
    }

    public function test_general_update_cannot_change_status_or_completion_timestamp(): void
    {
        $workOrder = $this->workOrder($this->branchA);

        $this->branchRequest()->patchJson('/api/v1/maintenance/work-orders/'.$workOrder, [
            'title' => 'Attempted status update',
            'priority' => 'high',
            'status' => 'completed',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder, 'status' => 'open', 'completed_at' => null]);
    }

    public function test_status_endpoint_controls_completion_and_audit(): void
    {
        $workOrder = $this->workOrder($this->branchA);

        $response = $this->branchRequest()->patchJson('/api/v1/maintenance/work-orders/'.$workOrder.'/status', ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertNotNull($response->json('data.completed_at'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'work_order.status_changed', 'entity_id' => $workOrder, 'branch_id' => $this->branchA]);
    }

    public function test_status_endpoint_remains_branch_scoped(): void
    {
        $workOrder = $this->workOrder($this->branchB);

        $this->branchRequest($this->branchA)->patchJson('/api/v1/maintenance/work-orders/'.$workOrder.'/status', ['status' => 'completed'])
            ->assertNotFound();
    }

    private function workOrder(int $branchId): int
    {
        $property = DB::table('properties')->insertGetId([
            'branch_id' => $branchId,
            'owner_customer_id' => $branchId === $this->branchA ? $this->customerA : $this->customerB,
            'property_code' => 'WO-'.$branchId.'-'.uniqid(),
            'property_type' => 'apartment',
            'name' => 'Work Order Property',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('work_orders')->insertGetId([
            'branch_id' => $branchId,
            'work_order_no' => 'WO-'.$branchId.'-'.uniqid(),
            'property_id' => $property,
            'title' => 'Open work order',
            'description' => 'Status test',
            'priority' => 'normal',
            'status' => 'open',
            'service_charge' => '0.00',
            'created_by' => $this->apiUser->id,
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
