<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PostAgreementPayment;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Agreements\UpdateInstallmentStatusRequest;
use App\Models\OwnerAgreement;
use App\Models\TenantAgreement;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AgreementInstallmentController extends Controller
{
    public function updateOwner(UpdateInstallmentStatusRequest $request, int $agreement, int $installment, BranchContext $context)
    {
        return $this->update($request, 'owner', OwnerAgreement::class, 'owner_agreement_installments', 'owner_agreement_id', $agreement, $installment, $context);
    }

    public function updateTenant(UpdateInstallmentStatusRequest $request, int $agreement, int $installment, BranchContext $context)
    {
        return $this->update($request, 'tenant', TenantAgreement::class, 'tenant_agreement_installments', 'tenant_agreement_id', $agreement, $installment, $context);
    }

    private function update(UpdateInstallmentStatusRequest $request, string $type, string $agreementModel, string $table, string $agreementKey, int $agreementId, int $installmentId, BranchContext $context)
    {
        $agreement = $agreementModel::query()->forBranch($context->id())->findOrFail($agreementId);
        Gate::authorize('view', $agreement);

        if ($request->validated('status') === 'paid') {
            $installment = DB::table($table)->where('branch_id', $context->id())->where($agreementKey, $agreementId)->where('id', $installmentId)->first();
            if (! $installment) {
                throw new ApiException('INSTALLMENT_NOT_FOUND', 'Installment not found in the active branch.', 404);
            }
            if ((float) $installment->amount <= (float) $installment->paid_amount || $installment->status === 'paid') {
                throw new ApiException('PAYMENT_ALREADY_POSTED', 'This installment has already been paid.', 422);
            }

            $transaction = app(PostAgreementPayment::class)->execute($type, $agreementId, $context->branch(), [
                'installment_id' => $installmentId,
                'amount' => number_format((float) $installment->amount - (float) $installment->paid_amount, 2, '.', ''),
                'payment_mode' => 'cash',
                'payment_date' => now()->toDateString(),
                'remarks' => 'Installment '.$installment->installment_no.' payment',
            ], request()->user()->getAuthIdentifier(), request()->header('Idempotency-Key'));

            return ['data' => $transaction];
        }

        $result = DB::transaction(function () use ($request, $table, $agreementKey, $agreementId, $installmentId, $context) {
            $installment = DB::table($table)->where('branch_id', $context->id())->where($agreementKey, $agreementId)->where('id', $installmentId)->lockForUpdate()->first();
            if (! $installment) {
                throw new ApiException('INSTALLMENT_NOT_FOUND', 'Installment not found in the active branch.', 404);
            }
            DB::table($table)->where('id', $installmentId)->update(['status' => $request->validated('status'), 'updated_at' => now()]);

            return DB::table($table)->where('id', $installmentId)->first();
        });

        return ['data' => $result];
    }
}
