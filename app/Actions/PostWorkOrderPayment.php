<?php

namespace App\Actions;

use App\Exceptions\ApiException;
use App\Models\AccountTransaction;
use App\Models\Branch;
use App\Models\WorkOrderPayment;
use App\Services\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;

class PostWorkOrderPayment
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    public function execute(WorkOrderPayment $payment, Branch $branch, int $userId, ?string $idempotencyKey): AccountTransaction
    {
        return DB::transaction(function () use ($payment, $branch, $userId, $idempotencyKey) {
            if ($idempotencyKey && ($existing = AccountTransaction::query()->where('branch_id', $branch->id)->where('idempotency_key', $idempotencyKey)->first())) {
                return $existing->load('party');
            }
            $line = WorkOrderPayment::query()->where('branch_id', $branch->id)->where('id', $payment->id)->lockForUpdate()->firstOrFail();
            if ($line->status === 'paid') {
                throw new ApiException('PAYMENT_ALREADY_POSTED', 'This work-order payment has already been posted.', 422);
            }
            $transaction = AccountTransaction::query()->create([
                'branch_id' => $branch->id,
                'document_no' => $this->numbers->next($branch, $line->direction === 'inward' ? 'INWARD_RECEIPT' : 'OUTWARD_RECEIPT', (int) now()->format('Y')),
                'direction' => $line->direction,
                'transaction_date' => now()->toDateString(),
                'payment_mode' => $line->payment_mode,
                'amount' => $line->amount,
                'source_type' => 'work_order_payment',
                'source_id' => $line->id,
                'remarks' => $line->particulars.' | '.$line->category,
                'status' => 'posted',
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);
            $line->update(['status' => 'paid']);
            DB::table('audit_logs')->insert(['branch_id' => $branch->id, 'user_id' => $userId, 'action' => 'work_order_payment_posted', 'entity_type' => 'account_transaction', 'entity_id' => $transaction->id, 'metadata_json' => json_encode(['work_order_payment_id' => $line->id, 'amount' => $line->amount]), 'created_at' => now(), 'updated_at' => now()]);

            return $transaction->load('party');
        });
    }
}
