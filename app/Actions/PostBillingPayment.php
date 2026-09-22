<?php

namespace App\Actions;

use App\Exceptions\ApiException;
use App\Models\AccountTransaction;
use App\Models\Branch;
use App\Models\InvoicePayment;
use App\Models\QuotationPayment;
use App\Services\DocumentNumberGenerator;
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
            $source = $line instanceof QuotationPayment ? 'quotation_payment' : 'invoice_payment';
            $transaction = AccountTransaction::query()->create(['branch_id' => $branch->id, 'document_no' => $this->numbers->next($branch, $line->direction === 'inward' ? 'INWARD_RECEIPT' : 'OUTWARD_RECEIPT', (int) now()->format('Y')), 'direction' => $line->direction, 'transaction_date' => now()->toDateString(), 'payment_mode' => $line->payment_mode, 'amount' => $line->amount, 'source_type' => $source, 'source_id' => $line->id, 'remarks' => $line->particulars, 'status' => 'posted', 'created_by' => $userId, 'posted_by' => $userId, 'posted_at' => now(), 'idempotency_key' => $idempotencyKey]);
            $line->update(['status' => 'paid']);

            return $transaction;
        });
    }
}
