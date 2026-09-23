<?php

namespace Tests\Feature\Api\Agreements;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class AgreementLifecycleTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    private int $tenantId;

    private int $propertyId;

    private int $ownerAgreementId;

    private int $tenantAgreementId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->tenantId = $this->customer($this->branchA, 'A-TENANT');
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $this->assignCustomerRole($this->branchA, $this->tenantId, 'tenant');
        $this->propertyId = DB::table('properties')->insertGetId([
            'branch_id' => $this->branchA, 'owner_customer_id' => $this->customerA, 'property_code' => 'A-LIFE-001',
            'property_type' => 'apartment', 'name' => 'Lifecycle Flat', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->apiUser, 'web');
    }

    public function test_owner_and_tenant_follow_the_authoritative_lifecycle(): void
    {
        $owner = $this->createOwner();
        $this->ownerAgreementId = $owner['id'];
        $tenant = $this->createTenant($owner['id']);
        $this->tenantAgreementId = $tenant['id'];

        $this->action('owner', $owner['id'], 'submit')->assertJsonPath('data.status', 'pending_approval');
        $this->action('tenant', $tenant['id'], 'submit')->assertJsonPath('data.status', 'pending_approval');
        $this->action('owner', $owner['id'], 'approve')->assertJsonPath('data.status', 'approved');
        $this->action('tenant', $tenant['id'], 'approve')->assertJsonPath('data.status', 'approved');
        $this->action('owner', $owner['id'], 'commence')->assertJsonPath('data.status', 'commenced');
        $this->action('tenant', $tenant['id'], 'commence')->assertJsonPath('data.status', 'commenced');
        $this->action('tenant', $tenant['id'], 'hold', ['reason' => 'Operational review'])->assertJsonPath('data.status', 'on_hold');
        $this->action('tenant', $tenant['id'], 'resume')->assertJsonPath('data.status', 'commenced');

        $this->assertDatabaseCount('tenant_agreement_status_history', 5);
        $this->assertDatabaseCount('owner_agreement_status_history', 3);
    }

    public function test_arbitrary_approval_and_locked_edits_are_rejected(): void
    {
        $owner = $this->createOwner();
        $this->action('owner', $owner['id'], 'approve')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
        $this->action('owner', $owner['id'], 'submit')->assertOk();
        $this->action('owner', $owner['id'], 'approve')->assertOk();

        $this->branchRequest()->patchJson('/api/v1/owner-agreements/'.$owner['id'], ['notes' => 'Cannot change after approval.'])
            ->assertForbidden();
    }

    public function test_extension_and_renewal_create_independent_history(): void
    {
        $owner = $this->createOwner('2100-12-31');
        $this->ownerAgreementId = $owner['id'];
        $tenant = $this->createTenant($owner['id'], '2099-12-31');

        $this->action('owner', $owner['id'], 'submit');
        $this->action('owner', $owner['id'], 'approve');
        $this->action('owner', $owner['id'], 'commence');
        $this->action('tenant', $tenant['id'], 'submit');
        $this->action('tenant', $tenant['id'], 'approve');
        $this->action('tenant', $tenant['id'], 'commence');

        $this->action('owner', $owner['id'], 'extend', ['new_end_date' => '2101-12-31', 'reason' => 'Renewed owner coverage'])
            ->assertJsonPath('data.end_date', '2101-12-31');
        $this->action('tenant', $tenant['id'], 'extend', ['new_end_date' => '2100-12-31', 'reason' => 'Tenant extension'])
            ->assertJsonPath('data.end_date', '2100-12-31');

        $renewal = $this->action('tenant', $tenant['id'], 'renew', ['start_date' => '2101-01-01', 'end_date' => '2101-12-31'])->assertOk()->json('data');
        $this->assertSame('draft', $renewal['status']);
        $this->assertSame($tenant['id'], $renewal['renewed_from_agreement_id']);
        $this->assertNotSame($tenant['agreement_no'], $renewal['agreement_no']);
        $this->assertDatabaseHas('tenant_agreement_status_history', ['tenant_agreement_id' => $tenant['id'], 'action' => 'extend']);
        $this->assertDatabaseHas('tenant_agreement_status_history', ['tenant_agreement_id' => $renewal['id'], 'action' => 'renew']);
    }

    public function test_cross_branch_lifecycle_action_is_not_visible(): void
    {
        $id = DB::table('owner_agreements')->insertGetId([
            'branch_id' => $this->branchB, 'agreement_no' => 'B-LIFE-001', 'owner_customer_id' => $this->customerB,
            'start_date' => '2020-01-01', 'end_date' => '2100-12-31', 'total_amount' => '1000.00', 'currency_code' => 'AED',
            'payment_count' => 1, 'payment_mode' => 'cash', 'status' => 'draft', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->branchRequest()->postJson('/api/v1/owner-agreements/'.$id.'/submit')->assertNotFound();
    }

    public function test_lifecycle_processor_commences_once_and_is_idempotent(): void
    {
        $id = DB::table('owner_agreements')->insertGetId([
            'branch_id' => $this->branchA, 'agreement_no' => 'A-AUTO-001', 'owner_customer_id' => $this->customerA,
            'start_date' => '2020-01-01', 'end_date' => '2100-12-31', 'total_amount' => '1000.00', 'currency_code' => 'AED',
            'payment_count' => 1, 'payment_mode' => 'cash', 'status' => 'approved', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('agreements:process-lifecycle')->assertSuccessful();
        $this->assertDatabaseHas('owner_agreements', ['id' => $id, 'status' => 'commenced']);
        $historyCount = DB::table('owner_agreement_status_history')->where('owner_agreement_id', $id)->count();
        $this->artisan('agreements:process-lifecycle')->assertSuccessful();
        $this->assertSame($historyCount, DB::table('owner_agreement_status_history')->where('owner_agreement_id', $id)->count());
    }

    public function test_lifecycle_processor_expires_commenced_and_on_hold_agreements(): void
    {
        foreach (['commenced', 'on_hold'] as $index => $status) {
            DB::table('owner_agreements')->insert([
                'branch_id' => $this->branchA, 'agreement_no' => 'A-EXP-'.$index, 'owner_customer_id' => $this->customerA,
                'start_date' => '2020-01-01', 'end_date' => '2020-12-31', 'total_amount' => '1000.00', 'currency_code' => 'AED',
                'payment_count' => 1, 'payment_mode' => 'cash', 'status' => $status, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('agreements:process-lifecycle')->assertSuccessful();
        $this->assertSame(0, DB::table('owner_agreements')->whereIn('status', ['commenced', 'on_hold'])->where('agreement_no', 'like', 'A-EXP-%')->count());
        $this->assertSame(2, DB::table('owner_agreements')->where('status', 'expired')->where('agreement_no', 'like', 'A-EXP-%')->count());
    }

    private function createOwner(string $endDate = '2099-12-31'): array
    {
        return $this->branchRequest()->postJson('/api/v1/owner-agreements', [
            'owner_customer_id' => $this->customerA, 'property_ids' => [$this->propertyId], 'start_date' => '2020-01-01',
            'end_date' => $endDate, 'total_amount' => '12000.00', 'currency_code' => 'AED', 'payment_count' => 12, 'payment_mode' => 'cash',
        ])->assertCreated()->json('data');
    }

    private function createTenant(int $ownerAgreementId, string $endDate = '2099-12-31'): array
    {
        return $this->branchRequest()->postJson('/api/v1/tenant-agreements', [
            'tenant_customer_id' => $this->tenantId,
            'properties' => [['property_id' => $this->propertyId, 'source_owner_agreement_id' => $ownerAgreementId]],
            'start_date' => '2020-01-01', 'end_date' => $endDate, 'total_amount' => '24000.00', 'currency_code' => 'AED',
            'payment_count' => 12, 'payment_mode' => 'cash',
        ])->assertCreated()->json('data');
    }

    private function action(string $type, int $id, string $action, array $payload = [])
    {
        return $this->branchRequest()->postJson("/api/v1/{$type}-agreements/{$id}/{$action}", $payload);
    }
}
