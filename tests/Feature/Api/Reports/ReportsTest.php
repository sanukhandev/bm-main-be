<?php

namespace Tests\Feature\Api\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->actingAs($this->apiUser, 'web');
    }

    public function test_agreement_reports_are_branch_scoped_and_financially_summarized(): void
    {
        $property = DB::table('properties')->insertGetId(['branch_id' => $this->branchA, 'owner_customer_id' => $this->customerA, 'property_code' => 'A-001', 'property_type' => 'apartment', 'name' => 'Flat A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $agreement = DB::table('owner_agreements')->insertGetId(['branch_id' => $this->branchA, 'agreement_no' => 'OA-A-001', 'owner_customer_id' => $this->customerA, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'total_amount' => '1200.00', 'currency_code' => 'AED', 'payment_count' => 1, 'payment_mode' => 'cash', 'status' => 'approved', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('owner_agreement_properties')->insert(['branch_id' => $this->branchA, 'owner_agreement_id' => $agreement, 'property_id' => $property, 'owner_customer_id' => $this->customerA, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('owner_agreement_installments')->insert(['branch_id' => $this->branchA, 'owner_agreement_id' => $agreement, 'installment_no' => 1, 'due_date' => '2026-01-01', 'amount' => '1200.00', 'paid_amount' => '200.00', 'payment_mode' => 'cash', 'status' => 'partially_paid', 'created_at' => now(), 'updated_at' => now()]);

        $this->branchRequest()->getJson('/api/v1/reports/owner-agreements')->assertOk()->assertJsonPath('data.0.agreement_no', 'OA-A-001')->assertJsonPath('meta.summary.total_outstanding', '1000.00');
        DB::table('owner_agreements')->insert(['branch_id' => $this->branchB, 'agreement_no' => 'OA-B-001', 'owner_customer_id' => $this->customerB, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'total_amount' => '500.00', 'currency_code' => 'AED', 'payment_count' => 1, 'payment_mode' => 'cash', 'status' => 'approved', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->branchRequest()->getJson('/api/v1/reports/owner-agreements')->assertJsonMissing(['agreement_no' => 'OA-B-001']);
    }

    public function test_financial_reports_require_accounts_permission_and_cash_report_excludes_other_modes(): void
    {
        DB::table('account_transactions')->insert([
            ['branch_id' => $this->branchA, 'document_no' => 'A-IN-1', 'direction' => 'inward', 'transaction_date' => '2026-09-23', 'payment_mode' => 'cash', 'amount' => '100.00', 'source_type' => 'manual', 'status' => 'posted', 'created_by' => $this->apiUser->id, 'posted_by' => $this->apiUser->id, 'posted_at' => now(), 'cheque_status' => null, 'created_at' => now(), 'updated_at' => now()],
            ['branch_id' => $this->branchA, 'document_no' => 'A-CH-1', 'direction' => 'inward', 'transaction_date' => '2026-09-23', 'payment_mode' => 'cheque', 'amount' => '50.00', 'source_type' => 'manual', 'status' => 'posted', 'created_by' => $this->apiUser->id, 'posted_by' => $this->apiUser->id, 'posted_at' => now(), 'cheque_status' => 'received', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->branchRequest()->getJson('/api/v1/reports/daily-cash-movement?date_from=2026-09-23&date_to=2026-09-23')->assertOk()->assertJsonPath('data.0.cash_in', 100);
        $this->branchRequest()->getJson('/api/v1/reports/inward-receipts')->assertOk()->assertJsonPath('meta.summary.transaction_count', 2);
    }

    public function test_invalid_report_date_range_is_rejected(): void
    {
        $this->branchRequest()->getJson('/api/v1/reports/owner-agreements?date_from=2026-09-24&date_to=2026-09-23')->assertUnprocessable();
    }

    public function test_dashboard_reports_and_intelligent_report_share_outstanding_and_occupancy_metrics(): void
    {
        $now = now();
        DB::table('customer_role_assignments')->insert([
            ['branch_id' => $this->branchA, 'customer_id' => $this->customerA, 'role' => 'tenant', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $property = DB::table('properties')->insertGetId([
            'branch_id' => $this->branchA, 'owner_customer_id' => $this->customerA, 'property_code' => 'A-PARITY-001',
            'property_type' => 'apartment', 'name' => 'Parity Property', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $ownerAgreement = DB::table('owner_agreements')->insertGetId([
            'branch_id' => $this->branchA, 'agreement_no' => 'OA-PARITY-001', 'owner_customer_id' => $this->customerA,
            'start_date' => $now->toDateString(), 'end_date' => $now->copy()->addDays(10)->toDateString(), 'total_amount' => '1000.00',
            'currency_code' => 'AED', 'payment_count' => 1, 'payment_mode' => 'cash', 'status' => 'commenced', 'lock_version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('owner_agreement_properties')->insert([
            'branch_id' => $this->branchA, 'owner_agreement_id' => $ownerAgreement, 'property_id' => $property,
            'owner_customer_id' => $this->customerA, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $agreement = DB::table('tenant_agreements')->insertGetId([
            'branch_id' => $this->branchA, 'agreement_no' => 'TA-PARITY-001', 'tenant_customer_id' => $this->customerA,
            'start_date' => $now->toDateString(), 'end_date' => $now->copy()->addDays(10)->toDateString(), 'total_amount' => '1000.00',
            'currency_code' => 'AED', 'payment_count' => 1, 'payment_mode' => 'cash', 'status' => 'commenced', 'lock_version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('tenant_agreement_properties')->insert([
            'branch_id' => $this->branchA, 'tenant_agreement_id' => $agreement, 'property_id' => $property,
            'source_owner_agreement_id' => $ownerAgreement, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('tenant_agreement_installments')->insert([
            'branch_id' => $this->branchA, 'tenant_agreement_id' => $agreement, 'installment_no' => 1, 'due_date' => $now->toDateString(),
            'amount' => '1000.00', 'paid_amount' => '250.00', 'payment_mode' => 'cash', 'status' => 'partially_paid',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $dashboard = $this->branchRequest()->getJson('/api/v1/dashboard/operational')->assertOk();
        $reports = $this->branchRequest()->getJson('/api/v1/reports/tenant-outstanding')->assertOk();
        $intelligent = $this->branchRequest()->getJson('/api/v1/reports/intelligent?period=this_month')->assertOk();

        $dashboard->assertJsonPath('data.financial_attention.tenant_receivables', '750.00')
            ->assertJsonPath('data.occupancy.occupied_properties', 1);
        $reports->assertJsonPath('meta.summary.total_outstanding', 750);
        $intelligent->assertJsonPath('data.summary.tenant_receivables', '750.00')
            ->assertJsonPath('data.summary.occupied_properties', 1);
    }
}
