<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\Skills\CollectionsHealthSkill;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class CollectionsHealthSkillTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_collections_use_inward_allocated_payments_and_remaining_balances(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'tenant');
        $agreement = $this->agreement($this->branchA, $this->customerA, 'TA-A-001');
        $past = $this->installment($agreement, 1, now()->subDays(31)->toDateString(), 10000, 4000);
        $future = $this->installment($agreement, 2, now()->addDays(3)->toDateString(), 8000, 0);
        $this->payment($agreement, $past, 4000, 'inward', 'IR-A-001');
        $this->payment($agreement, $future, 1000, 'outward', 'OR-A-001');

        $result = $this->runSkill('How much is overdue?', 'overdue_receivables');

        $this->assertSame('6000.00', $result->summaryMetrics['overdue_total']);
        $this->assertSame(1, $result->summaryMetrics['overdue_installment_count']);
        $this->assertSame('31-60', $result->breakdowns['aging'][2]['bucket']);
        $this->assertSame('6000.00', $result->breakdowns['aging'][2]['amount']);
    }

    public function test_collected_amount_respects_date_and_payment_direction(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'tenant');
        $agreement = $this->agreement($this->branchA, $this->customerA, 'TA-A-002');
        $installment = $this->installment($agreement, 1, now()->toDateString(), 20000, 0);
        $this->payment($agreement, $installment, 2500, 'inward', 'IR-A-002', now()->toDateString());
        $this->payment($agreement, $installment, 7000, 'outward', 'OR-A-002', now()->toDateString());
        $this->payment($agreement, $installment, 9000, 'inward', 'IR-A-003', now()->subMonth()->toDateString());

        $result = $this->runSkill('How much did we collect this month?', 'collections_summary', timeRange: ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()]);

        $this->assertSame('2500.00', $result->summaryMetrics['collected_amount']);
        $this->assertSame(1, $result->summaryMetrics['payment_count']);
        $this->assertSame(1, $result->summaryMetrics['unique_tenant_count']);
    }

    public function test_permission_denies_all_collection_data(): void
    {
        $roleId = DB::table('roles')->where('key', 'branch_admin')->value('id');
        $permissionId = DB::table('permissions')->where('key', 'accounts.view')->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();

        $result = $this->runSkill('How are collections?', 'collections_health');

        $this->assertSame([], $result->summaryMetrics);
        $this->assertSame([], $result->records);
        $this->assertSame('FINANCIAL_DATA_RESTRICTED', $result->warnings[0]['code']);
    }

    public function test_rank_and_threshold_use_backend_balances(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'tenant');
        $firstAgreement = $this->agreement($this->branchA, $this->customerA, 'TA-A-003');
        $firstInstallment = $this->installment($firstAgreement, 1, now()->subDays(5)->toDateString(), 25000, 1000);
        $other = $this->customer($this->branchA, 'A-009');
        $this->assignCustomerRole($this->branchA, $other, 'tenant');
        $secondAgreement = $this->agreement($this->branchA, $other, 'TA-A-004');
        $secondInstallment = $this->installment($secondAgreement, 1, now()->subDays(5)->toDateString(), 9000, 0);
        $this->payment($firstAgreement, $firstInstallment, 1000, 'inward', 'IR-A-004');
        $this->payment($secondAgreement, $secondInstallment, 1000, 'inward', 'IR-A-005');

        $result = $this->runSkill('Show overdue tenants above AED 10,000.', 'overdue_receivables', ['overdue_gt' => 10000]);

        $this->assertCount(1, $result->records);
        $this->assertSame('A-001', $result->records[0]['customer_code']);
        $this->assertSame('24000.00', $result->records[0]['overdue']);
    }

    public function test_upcoming_collections_return_scheduled_remaining_amounts(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'tenant');
        $agreement = $this->agreement($this->branchA, $this->customerA, 'TA-A-005');
        $this->installment($agreement, 1, now()->addDays(2)->toDateString(), 10000, 2500);

        $result = $this->runSkill('What is due this week?', 'upcoming_collections', timeRange: ['from' => now()->toDateString(), 'to' => now()->addDays(7)->toDateString()]);

        $this->assertSame('7500.00', $result->summaryMetrics['scheduled_due']);
        $this->assertSame(1, $result->summaryMetrics['installment_count']);
        $this->assertSame('7500.00', $result->records[0]['balance']);
    }

    public function test_aging_boundaries_use_remaining_balance(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'tenant');
        $agreement = $this->agreement($this->branchA, $this->customerA, 'TA-A-006');
        foreach ([1 => now(), 2 => now()->subDays(30), 3 => now()->subDays(31), 4 => now()->subDays(60), 5 => now()->subDays(61), 6 => now()->subDays(90), 7 => now()->subDays(91)] as $number => $date) {
            $this->installment($agreement, $number, $date->toDateString(), 100, 0);
        }

        $result = $this->runSkill('How are collections?', 'collections_health');
        $aging = collect($result->breakdowns['aging'])->keyBy('bucket');

        $this->assertSame('100.00', $aging['current']['amount']);
        $this->assertSame('100.00', $aging['1-30']['amount']);
        $this->assertSame('200.00', $aging['31-60']['amount']);
        $this->assertSame('200.00', $aging['61-90']['amount']);
        $this->assertSame('100.00', $aging['90+']['amount']);
    }

    public function test_branch_data_is_not_included(): void
    {
        $this->assignCustomerRole($this->branchB, $this->customerB, 'tenant');
        $agreement = $this->agreement($this->branchB, $this->customerB, 'TA-B-001');
        $installment = $this->installment($agreement, 1, now()->subDay()->toDateString(), 50000, 0, $this->branchB);
        $this->payment($agreement, $installment, 50000, 'inward', 'IR-B-001', now()->toDateString(), $this->branchB, $this->customerB);

        $result = $this->runSkill('How much is overdue?', 'overdue_receivables');

        $this->assertSame('0.00', $result->summaryMetrics['overdue_total']);
        $this->assertSame(0, $result->summaryMetrics['tenant_count_with_overdue']);
    }

    private function runSkill(string $message, string $intent, array $filters = [], ?array $timeRange = null)
    {
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));

        return app(CollectionsHealthSkill::class)->execute(new ZaakiyExecutionContext($this->apiUser, $branch, new IntentFrame($intent, ['collections_health'], 'aggregate', filters: $filters, timeRange: $timeRange, question: $message), now()->toIso8601String()));
    }

    private function agreement(int $branchId, int $tenantId, string $number): int
    {
        return DB::table('tenant_agreements')->insertGetId(['branch_id' => $branchId, 'agreement_no' => $number, 'tenant_customer_id' => $tenantId, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addYear()->toDateString(), 'total_amount' => 100000, 'currency_code' => 'AED', 'payment_count' => 12, 'payment_frequency' => 'monthly', 'payment_mode' => 'bank_transfer', 'status' => 'commenced', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function installment(int $agreementId, int $number, string $dueDate, int $amount, int $paid, ?int $branchId = null): int
    {
        return DB::table('tenant_agreement_installments')->insertGetId(['branch_id' => $branchId ?? $this->branchA, 'tenant_agreement_id' => $agreementId, 'installment_no' => $number, 'due_date' => $dueDate, 'amount' => $amount, 'paid_amount' => $paid, 'payment_mode' => 'cash', 'status' => $paid >= $amount ? 'paid' : ($paid > 0 ? 'partially_paid' : 'pending'), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payment(int $agreementId, int $installmentId, int $amount, string $direction, string $documentNo, ?string $date = null, ?int $branchId = null, ?int $customerId = null): void
    {
        $branchId ??= $this->branchA;
        $transactionId = DB::table('account_transactions')->insertGetId(['branch_id' => $branchId, 'document_no' => $documentNo, 'direction' => $direction, 'transaction_date' => $date ?? now()->toDateString(), 'payment_mode' => 'cash', 'amount' => $amount, 'party_customer_id' => $customerId ?? $this->customerA, 'source_type' => 'tenant_agreement', 'source_id' => $agreementId, 'status' => 'posted', 'created_by' => $this->apiUser->id, 'posted_by' => $this->apiUser->id, 'posted_at' => now(), 'idempotency_key' => strtolower($documentNo), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('account_transaction_allocations')->insert(['branch_id' => $branchId, 'account_transaction_id' => $transactionId, 'tenant_agreement_installment_id' => $installmentId, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function record(array $records, string $type): array
    {
        return collect($records)->firstWhere('type', $type);
    }
}
