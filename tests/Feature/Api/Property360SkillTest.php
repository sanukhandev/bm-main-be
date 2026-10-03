<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\Skills\Property360Skill;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class Property360SkillTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_authorized_property_360_returns_verified_operational_and_financial_summary(): void
    {
        $propertyId = $this->property($this->branchA, $this->customerA, 'P-A-204', 'Flat 204');
        $ownerAgreementId = $this->ownerAgreement($propertyId);
        $tenantAgreementId = $this->tenantAgreement($propertyId, $ownerAgreementId);
        DB::table('tenant_agreement_installments')->insert([
            'branch_id' => $this->branchA,
            'tenant_agreement_id' => $tenantAgreementId,
            'installment_no' => 1,
            'due_date' => now()->subDay()->toDateString(),
            'amount' => 15000,
            'paid_amount' => 2500,
            'payment_mode' => 'bank_transfer',
            'status' => 'partially_paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('CREATE TABLE vendors (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER, name VARCHAR(255), UNIQUE (id, branch_id))');
        DB::table('work_orders')->insert([
            'branch_id' => $this->branchA,
            'work_order_no' => 'WO-A-001',
            'property_id' => $propertyId,
            'title' => 'Air conditioner repair',
            'priority' => 'high',
            'status' => 'open',
            'service_charge' => 500,
            'created_by' => $this->apiUser->id,
            'opened_at' => now()->subDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement('PRAGMA foreign_keys = ON');

        $result = $this->runSkill('Show property P-A-204.');

        $this->assertSame('property_360', $result->intent);
        $this->assertSame('occupied', $result->summaryMetrics['occupancy_status']);
        $this->assertSame('12500.00', $result->summaryMetrics['tenant_outstanding']);
        $this->assertSame('12500.00', $result->summaryMetrics['tenant_overdue']);
        $this->assertSame(1, $result->summaryMetrics['open_work_order_count']);
        $this->assertSame('P-A-204', $result->sources[0]['label']);
        $this->assertSame('/app/properties/'.$propertyId, $result->navigation[0]['route']);
    }

    public function test_property_360_degrades_without_financial_permission(): void
    {
        $roleId = DB::table('roles')->where('key', 'branch_admin')->value('id');
        $permissionId = DB::table('permissions')->where('key', 'accounts.view')->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        $this->property($this->branchA, $this->customerA, 'P-A-205', 'Flat 205');

        $result = $this->runSkill('Tell me about P-A-205.');

        $this->assertArrayNotHasKey('tenant_outstanding', $result->summaryMetrics);
        $this->assertSame('FINANCIAL_DATA_RESTRICTED', $result->warnings[0]['code']);
        $this->assertFalse($result->meta['financial_included']);
    }

    public function test_cross_branch_property_is_not_exposed(): void
    {
        $this->property($this->branchB, $this->customerB, 'P-B-101', 'Flat 101');

        $result = $this->runSkill('Show property P-B-101.');

        $this->assertSame('PROPERTY_NOT_FOUND', $result->warnings[0]['code']);
        $this->assertSame([], $result->records);
    }

    public function test_ambiguous_property_name_is_not_guessed(): void
    {
        $this->property($this->branchA, $this->customerA, 'P-A-101', 'Flat 101');
        $this->property($this->branchA, $this->customerA, 'P-A-102', 'Flat 101');

        $result = $this->runSkill('Tell me about Flat 101.');

        $this->assertSame('PROPERTY_AMBIGUOUS', $result->warnings[0]['code']);
        $this->assertCount(2, $result->records);
    }

    private function runSkill(string $message)
    {
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));

        return app(Property360Skill::class)->execute(new ZaakiyExecutionContext(
            $this->apiUser,
            $branch,
            new IntentFrame('property_360', ['property_360'], 'detail', question: $message),
            now()->toIso8601String(),
        ));
    }

    private function property(int $branchId, int $ownerId, string $code, string $name): int
    {
        return DB::table('properties')->insertGetId([
            'branch_id' => $branchId,
            'owner_customer_id' => $ownerId,
            'property_code' => $code,
            'unit_number' => preg_replace('/\D+/', '', $name),
            'property_type' => 'apartment',
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ownerAgreement(int $propertyId): int
    {
        $id = DB::table('owner_agreements')->insertGetId([
            'branch_id' => $this->branchA,
            'agreement_no' => 'OA-A-001',
            'owner_customer_id' => $this->customerA,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
            'total_amount' => 60000,
            'currency_code' => 'AED',
            'payment_count' => 2,
            'payment_frequency' => 'monthly',
            'payment_mode' => 'bank_transfer',
            'status' => 'commenced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('owner_agreement_properties')->insert([
            'branch_id' => $this->branchA,
            'owner_agreement_id' => $id,
            'property_id' => $propertyId,
            'owner_customer_id' => $this->customerA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function tenantAgreement(int $propertyId, int $ownerAgreementId): int
    {
        $id = DB::table('tenant_agreements')->insertGetId([
            'branch_id' => $this->branchA,
            'agreement_no' => 'TA-A-001',
            'tenant_customer_id' => $this->customerA,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'total_amount' => 30000,
            'currency_code' => 'AED',
            'payment_count' => 2,
            'payment_frequency' => 'monthly',
            'payment_mode' => 'bank_transfer',
            'status' => 'commenced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_agreement_properties')->insert([
            'branch_id' => $this->branchA,
            'tenant_agreement_id' => $id,
            'property_id' => $propertyId,
            'source_owner_agreement_id' => $ownerAgreementId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
