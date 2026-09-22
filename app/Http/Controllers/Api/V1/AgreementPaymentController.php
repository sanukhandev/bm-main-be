<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PostAgreementPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounts\PostAgreementPaymentRequest;
use App\Http\Resources\Api\V1\AccountTransactionResource;
use App\Support\Branch\BranchContext;

class AgreementPaymentController extends Controller
{
    public function tenant(PostAgreementPaymentRequest $request, int $tenantAgreement, BranchContext $branchContext, PostAgreementPayment $action): AccountTransactionResource
    {
        return $this->post($request, 'tenant', $tenantAgreement, $branchContext, $action);
    }

    public function owner(PostAgreementPaymentRequest $request, int $ownerAgreement, BranchContext $branchContext, PostAgreementPayment $action): AccountTransactionResource
    {
        return $this->post($request, 'owner', $ownerAgreement, $branchContext, $action);
    }

    private function post(PostAgreementPaymentRequest $request, string $type, int $agreement, BranchContext $branchContext, PostAgreementPayment $action): AccountTransactionResource
    {
        $transaction = $action->execute($type, $agreement, $branchContext->branch(), $request->validated(), $request->user()->getAuthIdentifier(), $request->header('Idempotency-Key'));

        return new AccountTransactionResource($transaction);
    }
}
