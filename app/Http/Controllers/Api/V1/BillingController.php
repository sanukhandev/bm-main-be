<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PostBillingPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Billing\StoreBillingPaymentRequest;
use App\Http\Requests\Api\V1\Billing\StoreInvoiceRequest;
use App\Http\Requests\Api\V1\Billing\StoreQuotationRequest;
use App\Http\Requests\Api\V1\Billing\UpdateBillingPaymentStatusRequest;
use App\Http\Resources\Api\V1\BillingPaymentResource;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Http\Resources\Api\V1\QuotationResource;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Quotation;
use App\Models\QuotationPayment;
use App\Services\BillingDocumentService;
use App\Services\PaymentModeDetails;
use App\Services\PaymentLineState;
use App\Support\Branch\BranchContext;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function quotations(Request $request, BranchContext $context)
    {
        return QuotationResource::collection(Quotation::query()->forBranch($context->id())->with(['workOrder', 'vendor'])->latest()->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function quotation(int $quotation, BranchContext $context)
    {
        return new QuotationResource(Quotation::query()->forBranch($context->id())->with(['workOrder', 'vendor', 'lines', 'payments.accountTransaction'])->findOrFail($quotation));
    }

    public function storeQuotation(StoreQuotationRequest $request, BranchContext $context, BillingDocumentService $service)
    {
        return new QuotationResource($service->quotation($context->branch(), $request->validated(), $request->user()->getAuthIdentifier()));
    }

    public function updateQuotation(StoreQuotationRequest $request, int $quotation, BranchContext $context, BillingDocumentService $service)
    {
        $record = Quotation::query()->forBranch($context->id())->findOrFail($quotation);

        return new QuotationResource($service->quotation($context->branch(), $request->validated(), $request->user()->getAuthIdentifier(), $record));
    }

    public function deleteQuotation(int $quotation, BranchContext $context)
    {
        Quotation::query()->forBranch($context->id())->findOrFail($quotation)->update(['status' => 'rejected']);

        return response()->noContent();
    }

    public function convertQuotation(int $quotation, BranchContext $context, BillingDocumentService $service)
    {
        $record = Quotation::query()->forBranch($context->id())->findOrFail($quotation);

        return new InvoiceResource($service->convert($context->branch(), $record, request()->user()->getAuthIdentifier()));
    }

    public function addQuotationPayment(StoreBillingPaymentRequest $request, int $quotation, BranchContext $context)
    {
        Quotation::query()->forBranch($context->id())->findOrFail($quotation);

        return new BillingPaymentResource(QuotationPayment::query()->create(['branch_id' => $context->id(), 'quotation_id' => $quotation, 'created_by' => $request->user()->getAuthIdentifier(), ...PaymentModeDetails::normalize($request->validated())]));
    }

    public function quotationPaymentStatus(UpdateBillingPaymentStatusRequest $request, int $quotation, int $payment, BranchContext $context, PostBillingPayment $action)
    {
        Quotation::query()->forBranch($context->id())->findOrFail($quotation);
        $line = QuotationPayment::query()->where('branch_id', $context->id())->where('quotation_id', $quotation)->findOrFail($payment);
        PaymentLineState::assertCanChange($line->status, $request->validated('status'));
        if ($request->validated('status') === 'paid') {
            return ['data' => $action->execute($line, $context->branch(), $request->user()->getAuthIdentifier(), $request->header('Idempotency-Key'))];
        } $line->update(['status' => 'defaulted']);

        return new BillingPaymentResource($line->refresh());
    }

    public function invoices(Request $request, BranchContext $context)
    {
        return InvoiceResource::collection(Invoice::query()->forBranch($context->id())->with(['quotation', 'workOrder', 'vendor'])->latest()->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function invoice(int $invoice, BranchContext $context)
    {
        return new InvoiceResource(Invoice::query()->forBranch($context->id())->with(['quotation', 'workOrder', 'vendor', 'lines', 'payments.accountTransaction'])->findOrFail($invoice));
    }

    public function storeInvoice(StoreInvoiceRequest $request, BranchContext $context, BillingDocumentService $service)
    {
        return new InvoiceResource($service->invoice($context->branch(), $request->validated(), $request->user()->getAuthIdentifier()));
    }

    public function updateInvoice(StoreInvoiceRequest $request, int $invoice, BranchContext $context, BillingDocumentService $service)
    {
        $record = Invoice::query()->forBranch($context->id())->findOrFail($invoice);

        return new InvoiceResource($service->invoice($context->branch(), $request->validated(), $request->user()->getAuthIdentifier(), $record));
    }

    public function deleteInvoice(int $invoice, BranchContext $context)
    {
        Invoice::query()->forBranch($context->id())->findOrFail($invoice)->update(['status' => 'void']);

        return response()->noContent();
    }

    public function addInvoicePayment(StoreBillingPaymentRequest $request, int $invoice, BranchContext $context)
    {
        Invoice::query()->forBranch($context->id())->findOrFail($invoice);

        return new BillingPaymentResource(InvoicePayment::query()->create(['branch_id' => $context->id(), 'invoice_id' => $invoice, 'created_by' => $request->user()->getAuthIdentifier(), ...PaymentModeDetails::normalize($request->validated())]));
    }

    public function invoicePaymentStatus(UpdateBillingPaymentStatusRequest $request, int $invoice, int $payment, BranchContext $context, PostBillingPayment $action)
    {
        Invoice::query()->forBranch($context->id())->findOrFail($invoice);
        $line = InvoicePayment::query()->where('branch_id', $context->id())->where('invoice_id', $invoice)->findOrFail($payment);
        PaymentLineState::assertCanChange($line->status, $request->validated('status'));
        if ($request->validated('status') === 'paid') {
            return ['data' => $action->execute($line, $context->branch(), $request->user()->getAuthIdentifier(), $request->header('Idempotency-Key'))];
        } $line->update(['status' => 'defaulted']);

        return new BillingPaymentResource($line->refresh());
    }
}
