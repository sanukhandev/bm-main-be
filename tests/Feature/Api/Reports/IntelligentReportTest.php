<?php

namespace Tests\Feature\Api\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class IntelligentReportTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_branch_report_is_backend_calculated_and_voids_are_excluded(): void
    {
        DB::table('account_transactions')->insert([
            'branch_id' => $this->branchA, 'document_no' => 'IR-INT-1', 'direction' => 'inward', 'transaction_date' => now()->toDateString(), 'payment_mode' => 'cash', 'amount' => '1000.00', 'source_type' => 'manual', 'status' => 'posted', 'created_by' => $this->apiUser->id, 'posted_by' => $this->apiUser->id, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('account_transactions')->insert([
            'branch_id' => $this->branchA, 'document_no' => 'IR-INT-VOID', 'direction' => 'outward', 'transaction_date' => now()->toDateString(), 'payment_mode' => 'cash', 'amount' => '400.00', 'source_type' => 'manual', 'status' => 'void', 'created_by' => $this->apiUser->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->apiUser)->branchRequest()->getJson('/api/v1/reports/intelligent?period=this_month');

        $response->assertOk()->assertJsonPath('data.scope.type', 'branch')->assertJsonPath('data.summary.total_inward', '1000.00')->assertJsonPath('data.summary.total_outward', '0.00');
    }

    public function test_non_super_admin_cannot_request_overall_scope(): void
    {
        $this->actingAs($this->apiUser)->branchRequest()->getJson('/api/v1/reports/intelligent?period=this_month&scope=overall')->assertForbidden();
    }

    public function test_pdf_uses_the_same_authorized_report_scope(): void
    {
        $response = $this->actingAs($this->apiUser)->branchRequest()->get('/api/v1/reports/intelligent/pdf?period=this_month');

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_branch_report_includes_zaakiy_overview_period_comparisons_and_all_five_skills(): void
    {
        $propertyId = DB::table('properties')->insertGetId([
            'branch_id' => $this->branchA,
            'owner_customer_id' => $this->customerA,
            'property_code' => 'IR-FLAT-101',
            'unit_number' => '101',
            'property_type' => 'apartment',
            'name' => 'Flat 101',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('account_transactions')->insert([
            [
                'branch_id' => $this->branchA, 'document_no' => 'IR-CUR-1', 'direction' => 'inward',
                'transaction_date' => now()->toDateString(), 'payment_mode' => 'cash', 'amount' => '2500.00',
                'source_type' => 'manual', 'status' => 'posted', 'created_by' => $this->apiUser->id,
                'posted_by' => $this->apiUser->id, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'branch_id' => $this->branchA, 'document_no' => 'IR-PREV-1', 'direction' => 'inward',
                'transaction_date' => now()->startOfMonth()->subDays(5)->toDateString(), 'payment_mode' => 'cash', 'amount' => '1000.00',
                'source_type' => 'manual', 'status' => 'posted', 'created_by' => $this->apiUser->id,
                'posted_by' => $this->apiUser->id, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $ownerAgreementId = DB::table('owner_agreements')->insertGetId([
            'branch_id' => $this->branchA, 'agreement_no' => 'OA-IR-01', 'owner_customer_id' => $this->customerA,
            'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addDays(20)->toDateString(),
            'total_amount' => 12000, 'currency_code' => 'AED', 'payment_count' => 1, 'payment_frequency' => 'yearly',
            'payment_mode' => 'bank_transfer', 'status' => 'commenced', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('owner_agreement_properties')->insert([
            'branch_id' => $this->branchA, 'owner_agreement_id' => $ownerAgreementId,
            'property_id' => $propertyId, 'owner_customer_id' => $this->customerA,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->apiUser)->branchRequest()->getJson('/api/v1/reports/intelligent?period=this_month');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'zaakiy' => [
                        'overview' => ['headline', 'highlights', 'attention_items', 'period_comparisons', 'suggested_prompts'],
                        'skills' => ['collections', 'vacancy', 'agreement_risk', 'renewals', 'maintenance'],
                    ],
                ],
            ])
            ->assertJsonPath('data.zaakiy.skills.vacancy.metrics.eligible_property_count', 1)
            ->assertJsonPath('data.zaakiy.skills.vacancy.metrics.vacant_property_count', 1)
            ->assertJsonPath('data.zaakiy.skills.agreement_risk.metrics.expiring_soon_count', 1)
            ->assertJsonPath('data.zaakiy.skills.renewals.metrics.owner_renewal_count', 1)
            ->assertJsonPath('data.zaakiy.overview.period_comparisons.0.current_value', 2500)
            ->assertJsonPath('data.zaakiy.overview.period_comparisons.0.comparison_value', 1000)
            ->assertJsonPath('data.zaakiy.overview.period_comparisons.0.percentage_delta', 150);
    }

    public function test_super_admin_overall_scope_aggregates_zaakiy_skills_across_active_branches(): void
    {
        $superRoleId = DB::table('roles')->where('key', 'super_admin')->value('id');
        DB::table('user_global_roles')->insert([
            'user_id' => $this->apiUser->id,
            'role_id' => $superRoleId,
            'created_at' => now(),
        ]);

        DB::table('properties')->insert([
            [
                'branch_id' => $this->branchA, 'owner_customer_id' => $this->customerA, 'property_code' => 'OV-A-1',
                'unit_number' => '1', 'property_type' => 'apartment', 'name' => 'A Unit', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'branch_id' => $this->branchB, 'owner_customer_id' => $this->customerB, 'property_code' => 'OV-B-1',
                'unit_number' => '2', 'property_type' => 'villa', 'name' => 'B Villa', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($this->apiUser)->branchRequest()->getJson('/api/v1/reports/intelligent?period=this_month&scope=overall');

        $response->assertOk()
            ->assertJsonPath('data.scope.type', 'overall')
            ->assertJsonPath('data.zaakiy.skills.vacancy.metrics.eligible_property_count', 2)
            ->assertJsonPath('data.zaakiy.skills.vacancy.metrics.vacant_property_count', 2);
    }
}
