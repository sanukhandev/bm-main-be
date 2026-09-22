<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PostAdditionalAgreementPayment;
use App\Actions\TransitionAgreement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Agreements\StoreAdditionalPaymentRequest;
use App\Http\Requests\Api\V1\Agreements\StoreAgreementDisputeRequest;
use App\Http\Requests\Api\V1\Agreements\StoreDisputeCommentRequest;
use App\Http\Requests\Api\V1\Agreements\TransitionAgreementRequest;
use App\Http\Requests\Api\V1\Agreements\UpdateInstallmentStatusRequest;
use App\Models\AgreementAdditionalPayment;
use App\Models\AgreementDispute;
use App\Models\OwnerAgreement;
use App\Models\TenantAgreement;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\Gate;

class AgreementOperationsController extends Controller
{
    public function transition(TransitionAgreementRequest $request, string $type, int $agreement, BranchContext $context, TransitionAgreement $action)
    {
        $model = $type === 'owner' ? OwnerAgreement::class : TenantAgreement::class;
        $record = $model::query()->forBranch($context->id())->findOrFail($agreement);
        Gate::authorize('view', $record);

        return ['data' => $action->execute($type, $agreement, $context->id(), $request->validated('status'), $request->validated('reason'), $request->user()->getAuthIdentifier())];
    }

    public function disputes(StoreAgreementDisputeRequest $request, string $type, int $agreement, BranchContext $context)
    {
        $record = $this->agreement($type, $agreement, $context);
        $dispute = AgreementDispute::query()->create(['branch_id' => $context->id(), 'owner_agreement_id' => $type === 'owner' ? $agreement : null, 'tenant_agreement_id' => $type === 'tenant' ? $agreement : null, 'raised_by_user_id' => $request->user()->getAuthIdentifier(), ...$request->validated()]);

        return ['data' => $dispute->load('comments')];
    }

    public function comment(StoreDisputeCommentRequest $request, int $dispute, BranchContext $context)
    {
        $record = AgreementDispute::query()->where('branch_id', $context->id())->findOrFail($dispute);
        $comment = $record->comments()->create(['branch_id' => $context->id(), 'user_id' => $request->user()->getAuthIdentifier(), 'comment' => $request->validated('comment')]);

        return ['data' => $comment];
    }

    public function additionalPayment(StoreAdditionalPaymentRequest $request, string $type, int $agreement, BranchContext $context)
    {
        $this->agreement($type, $agreement, $context);
        $payment = AgreementAdditionalPayment::query()->create(['branch_id' => $context->id(), 'owner_agreement_id' => $type === 'owner' ? $agreement : null, 'tenant_agreement_id' => $type === 'tenant' ? $agreement : null, 'created_by' => $request->user()->getAuthIdentifier(), ...$request->validated()]);

        return ['data' => $payment];
    }

    public function additionalPaymentStatus(UpdateInstallmentStatusRequest $request, string $type, int $agreement, int $payment, BranchContext $context, PostAdditionalAgreementPayment $action)
    {
        $this->agreement($type, $agreement, $context);
        $line = AgreementAdditionalPayment::query()->where('branch_id', $context->id())->where('id', $payment)->where($type === 'owner' ? 'owner_agreement_id' : 'tenant_agreement_id', $agreement)->firstOrFail();

        if ($request->validated('status') === 'paid') {
            return ['data' => $action->execute($type, $agreement, $line, $context->branch(), $request->user()->getAuthIdentifier(), $request->header('Idempotency-Key'))];
        }

        $line->update(['status' => $request->validated('status')]);

        return ['data' => $line];
    }

    private function agreement(string $type, int $id, BranchContext $context)
    {
        $model = $type === 'owner' ? OwnerAgreement::class : TenantAgreement::class;
        $agreement = $model::query()->forBranch($context->id())->findOrFail($id);
        Gate::authorize('view', $agreement);

        return $agreement;
    }
}
