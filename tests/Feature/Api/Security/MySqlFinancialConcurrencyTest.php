<?php

namespace Tests\Feature\Api\Security;

use App\Actions\PostAdditionalAgreementPayment;
use App\Actions\PostAgreementPayment;
use App\Actions\PostBillingPayment;
use App\Actions\PostWorkOrderPayment;
use App\Actions\VoidAccountTransaction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\AccountsController;
use App\Models\AgreementAdditionalPayment;
use App\Models\Branch;
use App\Models\InvoicePayment;
use App\Models\Quotation;
use App\Models\User;
use App\Models\WorkOrderPayment;
use App\Services\BillingDocumentService;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class MySqlFinancialConcurrencyTest extends TestCase
{
    use ApiScenario, DatabaseMigrations {
        runDatabaseMigrations as runMySqlMigrations;
    }

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->runMySqlMigrations();
        }
    }

    public function test_simultaneous_billing_payment_retries_share_one_posted_transaction(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('This test requires separate MySQL connections and processes.');
        }

        $this->seedApiScenario();
        $invoiceId = DB::table('invoices')->insertGetId([
            'branch_id' => $this->branchA,
            'invoice_no' => 'CONCURRENT-INV-001',
            'title' => 'Concurrent payment test',
            'invoice_date' => now()->toDateString(),
            'status' => 'issued',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total_amount' => '100.00',
            'created_by' => $this->apiUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = DB::table('invoice_payments')->insertGetId([
            'branch_id' => $this->branchA,
            'invoice_id' => $invoiceId,
            'direction' => 'inward',
            'particulars' => 'Concurrent test payment',
            'amount' => '100.00',
            'payment_mode' => 'cash',
            'status' => 'pending',
            'created_by' => $this->apiUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $branchId = $this->branchA;
        $userId = $this->apiUser->id;
        $post = static function () use ($branchId, $paymentId, $userId): int {
            $transaction = app(PostBillingPayment::class)->execute(
                InvoicePayment::query()->findOrFail($paymentId),
                Branch::query()->findOrFail($branchId),
                $userId,
                'concurrent-billing-payment-001',
            );

            return $transaction->id;
        };

        $results = Concurrency::driver('process')->run([$post, $post]);

        $this->assertSame($results[0], $results[1]);
        $this->assertSame(1, DB::table('account_transactions')->where('idempotency_key', 'concurrent-billing-payment-001')->count());
        $this->assertDatabaseHas('account_transactions', ['id' => $results[0], 'status' => 'posted', 'source_id' => $paymentId]);
        $this->assertDatabaseHas('invoice_payments', ['id' => $paymentId, 'status' => 'paid']);

        $agreementId = DB::table('tenant_agreements')->insertGetId([
            'branch_id' => $branchId,
            'agreement_no' => 'CONCURRENT-TA-001',
            'tenant_customer_id' => $this->customerA,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '100.00',
            'currency_code' => 'AED',
            'payment_count' => 1,
            'payment_mode' => 'cash',
            'status' => 'commenced',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $installmentId = DB::table('tenant_agreement_installments')->insertGetId([
            'branch_id' => $branchId,
            'tenant_agreement_id' => $agreementId,
            'installment_no' => 1,
            'due_date' => '2026-01-01',
            'amount' => '100.00',
            'paid_amount' => '0.00',
            'payment_mode' => 'cash',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $postAgreementPayment = static function () use ($agreementId, $branchId, $installmentId, $userId): int {
            return app(PostAgreementPayment::class)->execute(
                'tenant',
                $agreementId,
                Branch::query()->findOrFail($branchId),
                ['amount' => '100.00', 'installment_id' => $installmentId, 'payment_mode' => 'cash', 'payment_date' => '2026-11-26'],
                $userId,
                'concurrent-agreement-payment-001',
            )->id;
        };
        $agreementResults = Concurrency::driver('process')->run([$postAgreementPayment, $postAgreementPayment]);
        $this->assertSame($agreementResults[0], $agreementResults[1]);
        $this->assertSame(1, DB::table('account_transactions')->where('idempotency_key', 'concurrent-agreement-payment-001')->count());
        $this->assertDatabaseHas('tenant_agreement_installments', ['id' => $installmentId, 'paid_amount' => '100.00', 'status' => 'paid']);

        $additionalPaymentId = DB::table('agreement_additional_payments')->insertGetId([
            'branch_id' => $branchId,
            'tenant_agreement_id' => $agreementId,
            'direction' => 'inward',
            'category' => 'other',
            'particulars' => 'Concurrent additional payment',
            'amount' => '10.00',
            'payment_mode' => 'cash',
            'status' => 'pending',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $postAdditionalPayment = static fn (): int => app(PostAdditionalAgreementPayment::class)->execute(
            'tenant',
            $agreementId,
            AgreementAdditionalPayment::query()->findOrFail($additionalPaymentId),
            Branch::query()->findOrFail($branchId),
            $userId,
            'concurrent-additional-payment-001',
        )->id;
        $additionalResults = Concurrency::driver('process')->run([$postAdditionalPayment, $postAdditionalPayment]);
        $this->assertSame($additionalResults[0], $additionalResults[1]);
        $this->assertSame(1, DB::table('account_transactions')->where('idempotency_key', 'concurrent-additional-payment-001')->count());
        $this->assertDatabaseHas('agreement_additional_payments', ['id' => $additionalPaymentId, 'status' => 'paid']);

        $propertyId = DB::table('properties')->insertGetId([
            'branch_id' => $branchId,
            'owner_customer_id' => $this->customerA,
            'property_code' => 'CONCURRENT-P-001',
            'property_type' => 'apartment',
            'name' => 'Concurrent payment property',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $workOrderId = DB::table('work_orders')->insertGetId([
            'branch_id' => $branchId,
            'work_order_no' => 'CONCURRENT-WO-001',
            'property_id' => $propertyId,
            'title' => 'Concurrent work-order payment',
            'status' => 'open',
            'service_charge' => '0.00',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $workOrderPaymentId = DB::table('work_order_payments')->insertGetId([
            'branch_id' => $branchId,
            'work_order_id' => $workOrderId,
            'direction' => 'outward',
            'category' => 'labour',
            'particulars' => 'Concurrent test work',
            'amount' => '25.00',
            'payment_mode' => 'cash',
            'status' => 'pending',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $postWorkOrderPayment = static fn (): int => app(PostWorkOrderPayment::class)->execute(
            WorkOrderPayment::query()->findOrFail($workOrderPaymentId),
            Branch::query()->findOrFail($branchId),
            $userId,
            'concurrent-work-order-payment-001',
        )->id;
        $workOrderResults = Concurrency::driver('process')->run([$postWorkOrderPayment, $postWorkOrderPayment]);
        $this->assertSame($workOrderResults[0], $workOrderResults[1]);
        $this->assertSame(1, DB::table('account_transactions')->where('idempotency_key', 'concurrent-work-order-payment-001')->count());
        $this->assertDatabaseHas('work_order_payments', ['id' => $workOrderPaymentId, 'status' => 'paid']);

        $quotation = app(BillingDocumentService::class)->quotation(Branch::query()->findOrFail($branchId), [
            'title' => 'Concurrent conversion',
            'quotation_date' => now()->toDateString(),
            'lines' => [['particulars' => 'Work', 'quantity' => '1', 'unit_price' => '50.00']],
        ], $userId);
        $quotationId = $quotation->id;
        $convert = static function () use ($branchId, $quotationId, $userId): array {
            try {
                $invoice = app(BillingDocumentService::class)->convert(
                    Branch::query()->findOrFail($branchId),
                    Quotation::query()->findOrFail($quotationId),
                    $userId,
                );

                return ['result' => 'converted', 'invoice_id' => $invoice->id];
            } catch (ApiException $exception) {
                return ['result' => 'conflict', 'status' => $exception->status];
            }
        };
        $conversionResults = Concurrency::driver('process')->run([$convert, $convert]);
        $this->assertEqualsCanonicalizing(['converted', 'conflict'], array_column($conversionResults, 'result'));
        $this->assertSame(1, DB::table('invoices')->where('quotation_id', $quotationId)->count());
        $this->assertDatabaseHas('quotations', ['id' => $quotationId, 'status' => 'converted']);

        $retryPayment = static fn (): int => app(PostBillingPayment::class)->execute(
            InvoicePayment::query()->findOrFail($paymentId),
            Branch::query()->findOrFail($branchId),
            $userId,
            'concurrent-billing-payment-001',
        )->id;
        $voidPayment = static fn (): string => app(VoidAccountTransaction::class)->execute(
            $results[0],
            Branch::query()->findOrFail($branchId),
            $userId,
            'Concurrent void test',
        )->status->value;
        $voidRace = Concurrency::driver('process')->run([$retryPayment, $voidPayment]);
        $this->assertSame($results[0], $voidRace[0]);
        $this->assertSame('void', $voidRace[1]);
        $this->assertDatabaseHas('invoice_payments', ['id' => $paymentId, 'status' => 'pending']);

        $chequeId = DB::table('account_transactions')->insertGetId([
            'branch_id' => $branchId,
            'document_no' => 'CONCURRENT-CHEQUE-001',
            'direction' => 'inward',
            'transaction_date' => now()->toDateString(),
            'payment_mode' => 'cheque',
            'amount' => '10.00',
            'source_type' => 'manual',
            'status' => 'posted',
            'created_by' => $userId,
            'posted_by' => $userId,
            'posted_at' => now(),
            'cheque_status' => 'deposited',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchIdForCheque = $branchId;
        $userIdForCheque = $userId;
        $clear = static fn (): string => self::changeChequeStatus($branchIdForCheque, $chequeId, $userIdForCheque, 'clear');
        $bounce = static fn (): string => self::changeChequeStatus($branchIdForCheque, $chequeId, $userIdForCheque, 'bounce');
        $chequeResults = Concurrency::driver('process')->run([$clear, $bounce]);
        $this->assertEqualsCanonicalizing(['changed', 'conflict'], $chequeResults);
        $this->assertContains(DB::table('account_transactions')->where('id', $chequeId)->value('cheque_status'), ['cleared', 'bounced']);
        $this->assertSame(1, DB::table('audit_logs')->whereIn('action', ['accounts.cheque_cleared', 'accounts.cheque_bounced'])->where('entity_id', $chequeId)->count());
    }

    private static function changeChequeStatus(int $branchId, int $transactionId, int $userId, string $action): string
    {
        $request = Request::create('/api/v1/accounts/transactions/'.$transactionId.'/cheque/'.$action, 'POST');
        $request->setUserResolver(static fn (): User => User::query()->findOrFail($userId));
        $context = new BranchContext;
        $context->set(Branch::query()->findOrFail($branchId));

        try {
            app(AccountsController::class)->chequeAction($request, $transactionId, $action, $context);

            return 'changed';
        } catch (ApiException $exception) {
            return $exception->status === 409 ? 'conflict' : 'error';
        }
    }
}
