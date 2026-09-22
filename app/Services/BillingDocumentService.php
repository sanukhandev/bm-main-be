<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

class BillingDocumentService
{
    public function quotation(Branch $branch, array $data, int $userId, ?Quotation $existing = null): Quotation
    {
        return DB::transaction(function () use ($branch, $data, $userId, $existing) {
            $lines = $data['lines'];
            unset($data['lines']);
            $this->validateLinks($branch->id, $data);
            $subtotal = collect($lines)->sum(fn ($line) => (float) $line['quantity'] * (float) $line['unit_price']);
            $data['subtotal'] = $subtotal;
            $data['tax_amount'] = (float) ($data['tax_amount'] ?? 0);
            $data['total_amount'] = $subtotal + $data['tax_amount'];
            $record = $existing ?: new Quotation;
            if (! $existing) {
                $data['quotation_no'] = app(DocumentNumberGenerator::class)->next($branch, 'QUOTATION', (int) date('Y', strtotime($data['quotation_date'])));
            }
            $record->forceFill(['branch_id' => $branch->id, 'created_by' => $existing?->created_by ?: $userId, ...$data])->save();
            if ($existing) {
                $record->lines()->delete();
            }
            foreach ($lines as $line) {
                $record->lines()->create(['branch_id' => $branch->id, 'particulars' => $line['particulars'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'line_total' => (float) $line['quantity'] * (float) $line['unit_price']]);
            }

            return $record->load(['workOrder', 'vendor', 'lines', 'payments']);
        });
    }

    public function invoice(Branch $branch, array $data, int $userId, ?Invoice $existing = null): Invoice
    {
        return DB::transaction(function () use ($branch, $data, $userId, $existing) {
            $lines = $data['lines'];
            unset($data['lines']);
            $this->validateLinks($branch->id, $data);
            $subtotal = collect($lines)->sum(fn ($line) => (float) $line['quantity'] * (float) $line['unit_price']);
            $data['subtotal'] = $subtotal;
            $data['tax_amount'] = (float) ($data['tax_amount'] ?? 0);
            $data['total_amount'] = $subtotal + $data['tax_amount'];
            $record = $existing ?: new Invoice;
            if (! $existing) {
                $data['invoice_no'] = app(DocumentNumberGenerator::class)->next($branch, 'INVOICE', (int) date('Y', strtotime($data['invoice_date'])));
            }
            $record->forceFill(['branch_id' => $branch->id, 'created_by' => $existing?->created_by ?: $userId, ...$data])->save();
            if ($existing) {
                $record->lines()->delete();
            }
            foreach ($lines as $line) {
                $record->lines()->create(['branch_id' => $branch->id, 'particulars' => $line['particulars'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'line_total' => (float) $line['quantity'] * (float) $line['unit_price']]);
            }

            return $record->load(['quotation', 'workOrder', 'vendor', 'lines', 'payments']);
        });
    }

    public function convert(Branch $branch, Quotation $quotation, int $userId): Invoice
    {
        return DB::transaction(function () use ($branch, $quotation, $userId) {
            $quotation->load('lines');
            $invoice = $this->invoice($branch, ['quotation_id' => $quotation->id, 'work_order_id' => $quotation->work_order_id, 'vendor_id' => $quotation->vendor_id, 'title' => $quotation->title, 'description' => $quotation->description, 'invoice_date' => now()->toDateString(), 'due_date' => $quotation->valid_until?->format('Y-m-d'), 'tax_amount' => $quotation->tax_amount, 'status' => 'draft', 'lines' => $quotation->lines->map(fn ($line) => ['particulars' => $line->particulars, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price])->all()], $userId);
            $quotation->update(['status' => 'converted']);

            return $invoice;
        });
    }

    private function validateLinks(int $branchId, array $data): void
    {
        if (! empty($data['work_order_id'])) {
            WorkOrder::query()->forBranch($branchId)->findOrFail($data['work_order_id']);
        }
        if (! empty($data['vendor_id'])) {
            Vendor::query()->forBranch($branchId)->findOrFail($data['vendor_id']);
        }
    }
}
