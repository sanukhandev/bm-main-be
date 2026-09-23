<?php

namespace App\Actions;

use App\Exceptions\ApiException;
use App\Models\AccountTransaction;
use App\Models\Branch;
use App\Models\InvoicePayment;
use App\Models\QuotationPayment;
use App\Services\AuditService;
use App\Services\DocumentNumberGenerator;
use App\Services\PaymentModeDetails;
use Illuminate\Support\Facades\DB;

class PostBillingPayment
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    public function execute(QuotationPayment|InvoicePayment $payment, Branch $branch, int $userId, ?string $idempotencyKey): AccountTransaction
    {
        return DB::transaction(function () use ($payment, $branch, $userId, $idempotencyKey) {
            if ($idempotencyKey && ($existing = AccountTransaction::query()->where('branch_id', $branch->id)->where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }
            $line = $payment::query()->where('branch_id', $branch->id)->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($line->status === 'paid') {
                throw new ApiException('PAYMENT_ALREADY_POSTED', 'This billing payment has already been posted.', 422);
            }
            $details = PaymentModeDetails::normalize(['payment_mode' => $line->payment_mode, 'remarks' => $line->particulars, 'cheque_no' => $line->cheque_no, 'cheque_date' => $line->cheque_date, 'bank_name' => $line->bank_name, 'bank_reference' => $line->bank_reference, 'transfer_date' => $line->transfer_date]);
            $source = $line instanceof QuotationPayment ? 'quotation_payment' : 'invoice_payment';
            $transaction = AccountTransaction::query()->create(['branch_id' => $branch->id, 'document_no' => $this->numbers->next($branch, $line->direction === 'inward' ? 'INWARD_RECEIPT' : 'OUTWARD_RECEIPT', (int) now($branch->timezone)->format('Y')), 'direction' => $line->direction, 'transaction_date' => now($branch->timezone)->toDateString(), 'payment_mode' => $details['payment_mode'], 'amount' => $line->amount, 'source_type' => $source, 'source_id' => $line->id, 'remarks' => $details['remarks'], 'cheque_no' => $details['cheque_no'] ?? null, 'cheque_date' => $details['cheque_date'] ?? null, 'bank_name' => $details['bank_name'] ?? null, 'bank_reference' => $details['bank_reference'] ?? null, 'transfer_date' => $details['transfer_date'] ?? null, 'status' => 'posted', 'created_by' => $userId, 'posted_by' => $userId, 'posted_at' => now(), 'idempotency_key' => $idempotencyKey]);
            $line->update(['status' => 'paid']);
            app(AuditService::class)->record('accounts.transaction_posted', $transaction, null, null, ['transaction_id' => $transaction->id, 'document_number' => $transaction->document_no, 'direction' => $transaction->direction->value, 'amount' => $transaction->amount, 'payment_mode' => $transaction->payment_mode->value, 'source_type' => $source, 'source_id' => $line->id], $branch->id, $userId);

            return $transaction;
        });
    }
}
