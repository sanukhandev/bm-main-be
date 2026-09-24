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
}
