<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\WorkOrder;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class BillingDocumentService
{
    public function quotation(Branch $branch, array $data, int $userId, ?Quotation $existing = null): Quotation
    {
        return DB::transaction(function () use ($branch, $data, $userId, $existing) {
            $existing = $existing ? Quotation::query()->where('branch_id', $branch->id)->lockForUpdate()->findOrFail($existing->getKey()) : null;
            if ($existing && in_array($existing->status, ['converted', 'rejected'], true)) {
                throw new ApiException('FINANCIAL_RECORD_IMMUTABLE', 'This quotation can no longer be edited.', 409);
            }
            $lines = $data['lines'];
            unset($data['lines']);
            $this->validateLinks($branch->id, $data);
            $subtotal = $this->subtotalCents($lines);
            $tax = Money::toCents($data['tax_amount'] ?? 0);
            $data['subtotal'] = Money::formatCents($subtotal);
            $data['tax_amount'] = Money::formatCents($tax);
            $data['total_amount'] = Money::formatCents($subtotal + $tax);
            $record = $existing ?: new Quotation;
            $before = $existing?->only(['quotation_no', 'title', 'status', 'work_order_id', 'vendor_id']);
            if (! $existing) {
                $data['quotation_no'] = app(DocumentNumberGenerator::class)->next($branch, 'QUOTATION', (int) date('Y', strtotime($data['quotation_date'])));
            }
            $record->forceFill(['branch_id' => $branch->id, 'created_by' => $existing?->created_by ?: $userId, ...$data])->save();
            if ($existing) {
                $record->lines()->delete();
            }
            foreach ($lines as $line) {
                $record->lines()->create(['branch_id' => $branch->id, 'particulars' => $line['particulars'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'line_total' => Money::formatCents($this->lineTotalCents($line))]);
            }
            app(AuditService::class)->record($existing ? 'quotation.updated' : 'quotation.created', $record, $before, $record->only(['quotation_no', 'title', 'status', 'work_order_id', 'vendor_id']), [], $branch->id, $userId);

            return $record->load(['workOrder', 'vendor', 'lines', 'payments']);
        });
    }

    public function invoice(Branch $branch, array $data, int $userId, ?Invoice $existing = null): Invoice
    {
        return DB::transaction(function () use ($branch, $data, $userId, $existing) {
            $existing = $existing ? Invoice::query()->where('branch_id', $branch->id)->lockForUpdate()->findOrFail($existing->getKey()) : null;
            if ($existing && in_array($existing->status, ['paid', 'void'], true)) {
                throw new ApiException('FINANCIAL_RECORD_IMMUTABLE', 'This invoice can no longer be edited.', 409);
            }
            $lines = $data['lines'];
            unset($data['lines']);
            $this->validateLinks($branch->id, $data);
            $subtotal = $this->subtotalCents($lines);
            $tax = Money::toCents($data['tax_amount'] ?? 0);
            $data['subtotal'] = Money::formatCents($subtotal);
            $data['tax_amount'] = Money::formatCents($tax);
            $data['total_amount'] = Money::formatCents($subtotal + $tax);
            $record = $existing ?: new Invoice;
            $before = $existing?->only(['invoice_no', 'title', 'status', 'quotation_id', 'work_order_id', 'vendor_id']);
            if (! $existing) {
                $data['invoice_no'] = app(DocumentNumberGenerator::class)->next($branch, 'INVOICE', (int) date('Y', strtotime($data['invoice_date'])));
            }
            $record->forceFill(['branch_id' => $branch->id, 'created_by' => $existing?->created_by ?: $userId, ...$data])->save();
            if ($existing) {
                $record->lines()->delete();
            }
            foreach ($lines as $line) {
                $record->lines()->create(['branch_id' => $branch->id, 'particulars' => $line['particulars'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'line_total' => Money::formatCents($this->lineTotalCents($line))]);
            }
            app(AuditService::class)->record($existing ? 'invoice.updated' : 'invoice.created', $record, $before, $record->only(['invoice_no', 'title', 'status', 'quotation_id', 'work_order_id', 'vendor_id']), [], $branch->id, $userId);

            return $record->load(['quotation', 'workOrder', 'vendor', 'lines', 'payments']);
        });
    }

    public function convert(Branch $branch, Quotation $quotation, int $userId): Invoice
    {
        return DB::transaction(function () use ($branch, $quotation, $userId) {
            $quotation = Quotation::query()->forBranch($branch->id)->lockForUpdate()->findOrFail($quotation->getKey());
            if ($quotation->status === 'converted') {
                throw new ApiException('QUOTATION_ALREADY_CONVERTED', 'This quotation has already been converted.', 409);
            }
            $quotation->load('lines');
            $invoice = $this->invoice($branch, ['quotation_id' => $quotation->id, 'work_order_id' => $quotation->work_order_id, 'vendor_id' => $quotation->vendor_id, 'title' => $quotation->title, 'description' => $quotation->description, 'invoice_date' => now()->toDateString(), 'due_date' => $quotation->valid_until?->format('Y-m-d'), 'tax_amount' => $quotation->tax_amount, 'status' => 'draft', 'lines' => $quotation->lines->map(fn ($line) => ['particulars' => $line->particulars, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price])->all()], $userId);
            $before = $quotation->only(['quotation_no', 'title', 'status']);
            $quotation->update(['status' => 'converted']);
            app(AuditService::class)->record('quotation.converted', $quotation, $before, $quotation->only(['quotation_no', 'title', 'status']), [], $branch->id, $userId);

            return $invoice;
        });
    }

    public function rejectQuotation(Branch $branch, int $quotationId, int $userId): void
    {
        DB::transaction(function () use ($branch, $quotationId, $userId): void {
            $quotation = Quotation::query()->where('branch_id', $branch->id)->lockForUpdate()->findOrFail($quotationId);
            if (in_array($quotation->status, ['converted', 'rejected'], true)) {
                throw new ApiException('FINANCIAL_RECORD_IMMUTABLE', 'This quotation can no longer be rejected.', 409);
            }
            $before = $quotation->only(['quotation_no', 'title', 'status']);
            $quotation->update(['status' => 'rejected']);
            app(AuditService::class)->record('quotation.rejected', $quotation, $before, $quotation->only(['quotation_no', 'title', 'status']), [], $branch->id, $userId);
        });
    }

    public function voidInvoice(Branch $branch, int $invoiceId, int $userId): void
    {
        DB::transaction(function () use ($branch, $invoiceId, $userId): void {
            $invoice = Invoice::query()->where('branch_id', $branch->id)->lockForUpdate()->findOrFail($invoiceId);
            if (in_array($invoice->status, ['paid', 'void'], true)) {
                throw new ApiException('FINANCIAL_RECORD_IMMUTABLE', 'This invoice can no longer be voided.', 409);
            }
            $before = $invoice->only(['invoice_no', 'title', 'status']);
            $invoice->update(['status' => 'void']);
            app(AuditService::class)->record('invoice.voided', $invoice, $before, $invoice->only(['invoice_no', 'title', 'status']), [], $branch->id, $userId);
        });
    }

    private function validateLinks(int $branchId, array $data): void
    {
        if (! empty($data['quotation_id'])) {
            Quotation::query()->forBranch($branchId)->findOrFail($data['quotation_id']);
        }

        if (! empty($data['work_order_id'])) {
            WorkOrder::query()->forBranch($branchId)->findOrFail($data['work_order_id']);
        }
        if (! empty($data['vendor_id'])) {
            Customer::query()->forBranch($branchId)->whereKey($data['vendor_id'])->where('status', 'active')->whereNull('deleted_at')->whereHas('businessRoles', fn ($query) => $query->where('role', 'vendor'))->firstOrFail();
        }
    }

    private function subtotalCents(array $lines): int
    {
        return collect($lines)->sum(fn (array $line): int => $this->lineTotalCents($line));
    }

    private function lineTotalCents(array $line): int
    {
        return (int) round((float) $line['quantity'] * Money::toCents($line['unit_price']));
    }
}
