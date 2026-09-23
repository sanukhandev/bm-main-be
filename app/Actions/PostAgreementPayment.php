<?php

namespace App\Actions;

use App\Exceptions\ApiException;
use App\Models\AccountTransaction;
use App\Models\AccountTransactionAllocation;
use App\Models\Branch;
use App\Services\DocumentNumberGenerator;
use App\Services\PaymentModeDetails;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PostAgreementPayment
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    public function execute(string $type, int $agreementId, Branch $branch, array $data, int $userId, ?string $idempotencyKey): AccountTransaction
    {
        return DB::transaction(function () use ($type, $agreementId, $branch, $data, $userId, $idempotencyKey) {
            if ($idempotencyKey) {
                $existing = AccountTransaction::query()->where('branch_id', $branch->id)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing->load('allocations', 'party');
                }
            }

            $agreementTable = $type === 'owner' ? 'owner_agreements' : 'tenant_agreements';
            $agreement = DB::table($agreementTable)->where('branch_id', $branch->id)->where('id', $agreementId)->lockForUpdate()->first();
            if (! $agreement || ! in_array($agreement->status, ['approved', 'commenced'], true)) {
                throw new ApiException('AGREEMENT_NOT_PAYABLE', 'The agreement is not payable.', 422);
            }

            $installmentTable = $type === 'owner' ? 'owner_agreement_installments' : 'tenant_agreement_installments';
            $installmentKey = $type === 'owner' ? 'owner_agreement_id' : 'tenant_agreement_id';
            $installments = DB::table($installmentTable)->where('branch_id', $branch->id)->where($installmentKey, $agreementId)->whereIn('status', ['pending', 'partially_paid'])
                ->when($data['installment_id'] ?? null, fn ($query, $installmentId) => $query->where('id', $installmentId))
                ->orderBy('installment_no')->lockForUpdate()->get();
            if ($installments->isEmpty() && ! empty($data['installment_id'])) {
                $exists = DB::table($installmentTable)->where('branch_id', $branch->id)->where($installmentKey, $agreementId)->where('id', $data['installment_id'])->exists();
                if ($exists) {
                    throw new ApiException('PAYMENT_ALREADY_POSTED', 'This installment has no outstanding balance.', 422);
                }
            }
            $amountCents = $this->cents((string) $data['amount']);
            $outstandingCents = $installments->sum(fn ($row) => $this->cents((string) $row->amount) - $this->cents((string) $row->paid_amount));
            if ($amountCents < 1) {
                throw new ApiException('INVALID_PAYMENT_AMOUNT', 'Payment amount must be greater than zero.', 422);
            }
            if ($amountCents > $outstandingCents) {
                throw new ApiException('PAYMENT_EXCEEDS_OUTSTANDING', 'Payment exceeds the outstanding agreement balance.', 422);
            }

            $direction = match ($type) {
                'tenant' => 'inward',
                'owner' => 'outward',
                default => throw new ApiException('INVALID_AGREEMENT_TYPE', 'Unsupported agreement payment type.', 422),
            };
            $documentType = $direction === 'outward' ? 'OUTWARD_RECEIPT' : 'INWARD_RECEIPT';
            $partyId = $direction === 'outward' ? $agreement->owner_customer_id : $agreement->tenant_customer_id;
            $paymentSequence = ((int) DB::table('account_transactions')->where('branch_id', $branch->id)->where('source_type', "{$type}_agreement")->where('source_id', $agreementId)->lockForUpdate()->max('payment_sequence')) + 1;
            $details = PaymentModeDetails::normalize($data, $paymentSequence);
            $transaction = AccountTransaction::query()->create([
                'branch_id' => $branch->id,
                'document_no' => $this->numbers->next($branch, $documentType, (int) CarbonImmutable::parse($data['payment_date'], $branch->timezone)->format('Y')),
                'direction' => $direction,
                'transaction_date' => $data['payment_date'],
                'payment_mode' => $details['payment_mode'],
                'amount' => number_format($amountCents / 100, 2, '.', ''),
                'party_customer_id' => $partyId,
                'source_type' => "{$type}_agreement",
                'source_id' => $agreementId,
                'payment_sequence' => $paymentSequence,
                'remarks' => $details['remarks'],
                'cheque_no' => $details['cheque_no'] ?? null,
                'cheque_date' => $details['cheque_date'] ?? null,
                'bank_name' => $details['bank_name'] ?? null,
                'bank_reference' => $details['bank_reference'] ?? null,
                'transfer_date' => $details['transfer_date'] ?? null,
                'status' => 'posted',
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            $remaining = $amountCents;
            foreach ($installments as $installment) {
                if ($remaining === 0) {
                    break;
                }
                $balance = $this->cents((string) $installment->amount) - $this->cents((string) $installment->paid_amount);
                $allocated = min($remaining, $balance);
                DB::table($installmentTable)->where('id', $installment->id)->update([
                    'paid_amount' => number_format(($this->cents((string) $installment->paid_amount) + $allocated) / 100, 2, '.', ''),
                    'status' => $allocated === $balance ? 'paid' : 'partially_paid',
                    'updated_at' => now(),
                ]);
                AccountTransactionAllocation::query()->create([
                    'branch_id' => $branch->id,
                    'account_transaction_id' => $transaction->id,
                    $type === 'owner' ? 'owner_agreement_installment_id' : 'tenant_agreement_installment_id' => $installment->id,
                    'amount' => number_format($allocated / 100, 2, '.', ''),
                ]);
                $remaining -= $allocated;
            }

            DB::table('audit_logs')->insert(['branch_id' => $branch->id, 'user_id' => $userId, 'action' => 'payment_posted', 'entity_type' => 'account_transaction', 'entity_id' => $transaction->id, 'metadata_json' => json_encode(['amount' => $transaction->amount, 'source_type' => $transaction->source_type, 'source_id' => $agreementId]), 'created_at' => now(), 'updated_at' => now()]);

            return $transaction->load('allocations', 'party');
        });
    }

    private function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
