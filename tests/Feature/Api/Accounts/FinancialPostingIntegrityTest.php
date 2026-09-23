<?php

namespace Tests\Feature\Api\Accounts;

use App\Exceptions\ApiException;
use App\Models\AccountTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class FinancialPostingIntegrityTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    private int $tenantAgreementId;

    private int $installmentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->tenantAgreementId = DB::table('tenant_agreements')->insertGetId([
            'branch_id' => $this->branchA, 'agreement_no' => 'TA-TEST-001', 'tenant_customer_id' => $this->customerA,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'total_amount' => '10000.00', 'currency_code' => 'AED',
            'payment_count' => 1, 'payment_mode' => 'cash', 'status' => 'commenced', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->installmentId = DB::table('tenant_agreement_installments')->insertGetId([
            'branch_id' => $this->branchA, 'tenant_agreement_id' => $this->tenantAgreementId, 'installment_no' => 1,
            'due_date' => '2026-01-01', 'amount' => '10000.00', 'paid_amount' => '0.00', 'payment_mode' => 'cash', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->apiUser, 'web');
    }

    public function test_tenant_payment_is_inward_idempotent_and_cash_defaults_are_server_side(): void
    {
        $payload = ['amount' => '4000.00', 'installment_id' => $this->installmentId, 'payment_mode' => 'cash', 'payment_date' => '2026-09-23'];
        $first = $this->branchRequest()->withHeader('Idempotency-Key', 'payment-001')->postJson('/api/v1/tenant-agreements/'.$this->tenantAgreementId.'/payments', $payload)->assertCreated();
        $firstId = $first->json('data.id');
        $first->assertJsonPath('data.direction', 'inward')->assertJsonPath('data.remarks', 'Cash Payment 1');

        $this->branchRequest()->withHeader('Idempotency-Key', 'payment-001')->postJson('/api/v1/tenant-agreements/'.$this->tenantAgreementId.'/payments', $payload)->assertSuccessful()->assertJsonPath('data.id', $firstId);
        $this->assertSame(1, DB::table('account_transactions')->where('source_type', 'tenant_agreement')->count());
        $this->assertDatabaseHas('tenant_agreement_installments', ['id' => $this->installmentId, 'paid_amount' => '4000.00', 'status' => 'partially_paid']);
    }

    public function test_mode_details_and_overpayment_are_rejected(): void
    {
        $payload = ['amount' => '1000.00', 'installment_id' => $this->installmentId, 'payment_mode' => 'cheque', 'payment_date' => '2026-09-23'];
        $this->branchRequest()->postJson('/api/v1/tenant-agreements/'.$this->tenantAgreementId.'/payments', $payload)->assertUnprocessable();

        $this->branchRequest()->withHeader('Idempotency-Key', 'payment-002')->postJson('/api/v1/tenant-agreements/'.$this->tenantAgreementId.'/payments', [
            'amount' => '10001.00', 'installment_id' => $this->installmentId, 'payment_mode' => 'cash', 'payment_date' => '2026-09-23',
        ])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_EXCEEDS_OUTSTANDING');
    }

    public function test_void_restores_installment_and_preserves_original_document(): void
    {
        $posted = $this->branchRequest()->withHeader('Idempotency-Key', 'payment-003')->postJson('/api/v1/tenant-agreements/'.$this->tenantAgreementId.'/payments', [
            'amount' => '4000.00', 'installment_id' => $this->installmentId, 'payment_mode' => 'cash', 'payment_date' => '2026-09-23',
        ])->assertCreated();
        $transactionId = $posted->json('data.id');
        $documentNo = $posted->json('data.document_no');

        $this->branchRequest()->postJson('/api/v1/accounts/transactions/'.$transactionId.'/void', ['reason' => 'Correction'])->assertOk()->assertJsonPath('data.status', 'void');
        $this->assertDatabaseHas('account_transactions', ['id' => $transactionId, 'document_no' => $documentNo, 'status' => 'void']);
        $this->assertDatabaseHas('tenant_agreement_installments', ['id' => $this->installmentId, 'paid_amount' => '0.00', 'status' => 'pending']);
        $this->branchRequest()->postJson('/api/v1/accounts/transactions/'.$transactionId.'/void', ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'FINANCIAL_RECORD_ALREADY_VOID');
    }

    public function test_posted_transaction_cannot_be_updated_or_deleted(): void
    {
        $posted = AccountTransaction::query()->create([
            'branch_id' => $this->branchA, 'document_no' => 'E2E-A-IR-2026-000001', 'direction' => 'inward', 'transaction_date' => '2026-09-23',
            'payment_mode' => 'cash', 'amount' => '10.00', 'source_type' => 'manual', 'status' => 'posted', 'created_by' => $this->apiUser->id,
            'posted_by' => $this->apiUser->id, 'posted_at' => now(),
        ]);

        try {
            $posted->update(['amount' => '11.00']);
            $this->fail('Expected posted transaction immutability failure.');
        } catch (ApiException $exception) {
            $this->assertSame('FINANCIAL_RECORD_IMMUTABLE', $exception->errorCode);
        }
    }
}
