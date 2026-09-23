<?php

namespace App\Actions;

use App\Exceptions\ApiException;
use App\Models\AccountTransaction;
use App\Models\Branch;
use Illuminate\Support\Facades\DB;

class VoidAccountTransaction
{
    public function execute(int $id, Branch $branch, int $userId, string $reason): AccountTransaction
    {
        return DB::transaction(function () use ($id, $branch, $userId, $reason) {
            if (blank($reason)) {
                throw new ApiException('VOID_REASON_REQUIRED', 'A reason is required to void a financial record.', 422);
            }

            $transaction = AccountTransaction::query()->where('branch_id', $branch->id)->lockForUpdate()->findOrFail($id);
            if ($transaction->status->value === 'void') {
                throw new ApiException('FINANCIAL_RECORD_ALREADY_VOID', 'This financial record is already void.', 409);
            }
            if ($transaction->status->value !== 'posted') {
                throw new ApiException('INVALID_FINANCIAL_STATUS', 'Only posted financial records can be voided.', 409);
            }

            foreach ($transaction->allocations()->where('branch_id', $branch->id)->orderBy('id')->lockForUpdate()->get() as $allocation) {
                $table = $allocation->tenant_agreement_installment_id ? 'tenant_agreement_installments' : 'owner_agreement_installments';
                $column = $allocation->tenant_agreement_installment_id ? 'tenant_agreement_installment_id' : 'owner_agreement_installment_id';
                $installmentId = $allocation->{$column};
                $installment = DB::table($table)->where('branch_id', $branch->id)->lockForUpdate()->find($installmentId);
                $paid = $this->cents((string) $installment->paid_amount) - $this->cents((string) $allocation->amount);
                if ($paid < 0) {
                    throw new ApiException('PAYMENT_ALLOCATION_INVALID', 'The posted allocation cannot be reversed safely.', 409);
                }
                DB::table($table)->where('id', $installmentId)->update([
                    'paid_amount' => number_format($paid / 100, 2, '.', ''),
                    'status' => $paid === 0 ? 'pending' : ($paid >= $this->cents((string) $installment->amount) ? 'paid' : 'partially_paid'),
                    'updated_at' => now(),
                ]);
            }

            $sourceTables = [
                'agreement_additional_payment' => ['agreement_additional_payments', 'id'],
                'work_order_payment' => ['work_order_payments', 'id'],
                'invoice_payment' => ['invoice_payments', 'id'],
                'quotation_payment' => ['quotation_payments', 'id'],
            ];
            if (isset($sourceTables[$transaction->source_type])) {
                [$table, $key] = $sourceTables[$transaction->source_type];
                DB::table($table)->where('id', $transaction->source_id)->where('branch_id', $branch->id)->update(['status' => 'pending', 'updated_at' => now()]);
            }

            DB::table('account_transactions')->where('id', $transaction->id)->update([
                'status' => 'void', 'voided_by' => $userId, 'voided_at' => now(), 'void_reason' => $reason, 'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert(['branch_id' => $branch->id, 'user_id' => $userId, 'action' => 'payment_voided', 'entity_type' => 'account_transaction', 'entity_id' => $transaction->id, 'metadata_json' => json_encode(['reason' => $reason]), 'created_at' => now(), 'updated_at' => now()]);

            return $transaction->refresh();
        });
    }

    private function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
