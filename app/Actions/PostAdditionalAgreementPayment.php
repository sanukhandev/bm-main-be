<?php

namespace App\Actions;

use App\Exceptions\ApiException;
use App\Models\AccountTransaction;
use App\Models\AgreementAdditionalPayment;
use App\Models\Branch;
use App\Services\AuditService;
use App\Services\DocumentNumberGenerator;
use App\Services\PaymentModeDetails;
use Illuminate\Support\Facades\DB;

class PostAdditionalAgreementPayment
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    public function execute(string $type, int $agreementId, AgreementAdditionalPayment $line, Branch $branch, int $userId, ?string $idempotencyKey): AccountTransaction
    {
        return DB::transaction(function () use ($type, $agreementId, $line, $branch, $userId, $idempotencyKey) {
            if ($idempotencyKey && ($existing = AccountTransaction::query()->where('branch_id', $branch->id)->where('idempotency_key', $idempotencyKey)->first())) {
                return $existing->load('party');
            }

            $lockedLine = AgreementAdditionalPayment::query()->where('branch_id', $branch->id)->where('id', $line->id)->lockForUpdate()->firstOrFail();
            if ($lockedLine->status === 'paid') {
                throw new ApiException('PAYMENT_ALREADY_POSTED', 'This additional payment has already been posted.', 422);
            }
            $agreementTable = $type === 'owner' ? 'owner_agreements' : 'tenant_agreements';
            $agreementStatus = DB::table($agreementTable)->where('branch_id', $branch->id)->where('id', $agreementId)->value('status');
            if (! in_array($agreementStatus, ['approved', 'commenced'], true)) {
                throw new ApiException('AGREEMENT_NOT_PAYABLE', 'The agreement is not payable.', 422);
            }

            // Agreement type is authoritative: owners are paid outward and tenants pay inward.
            $direction = $type === 'owner' ? 'outward' : 'inward';
            $details = PaymentModeDetails::normalize(['payment_mode' => $lockedLine->payment_mode, 'remarks' => $lockedLine->particulars, 'cheque_no' => $lockedLine->cheque_no, 'cheque_date' => $lockedLine->cheque_date, 'bank_name' => $lockedLine->bank_name, 'bank_reference' => $lockedLine->bank_reference, 'transfer_date' => $lockedLine->transfer_date]);
            $transaction = AccountTransaction::query()->create([
                'branch_id' => $branch->id,
                'document_no' => $this->numbers->next($branch, $direction === 'inward' ? 'INWARD_RECEIPT' : 'OUTWARD_RECEIPT', (int) now($branch->timezone)->format('Y')),
                'direction' => $direction,
                'transaction_date' => now()->toDateString(),
                'payment_mode' => $details['payment_mode'],
                'amount' => $lockedLine->amount,
                'party_customer_id' => $this->partyId($type, $agreementId, $branch),
                'source_type' => "{$type}_agreement_additional_payment",
                'source_id' => $lockedLine->id,
                'remarks' => $details['remarks'].' | '.$lockedLine->category,
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

            $lockedLine->forceFill(['status' => 'paid'])->save();
            app(AuditService::class)->record('accounts.transaction_posted', $transaction, null, null, ['transaction_id' => $transaction->id, 'document_number' => $transaction->document_no, 'direction' => $direction, 'amount' => $transaction->amount, 'payment_mode' => $transaction->payment_mode->value, 'source_type' => $transaction->source_type, 'source_id' => $lockedLine->id, 'agreement_id' => $agreementId], $branch->id, $userId);

            return $transaction->load('party');
        });
    }

    private function partyId(string $type, int $agreementId, Branch $branch): int
    {
        $table = $type === 'owner' ? 'owner_agreements' : 'tenant_agreements';
        $column = $type === 'owner' ? 'owner_customer_id' : 'tenant_customer_id';

        return (int) DB::table($table)->where('branch_id', $branch->id)->where('id', $agreementId)->value($column);
    }
}
