<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Services\DocumentNumberGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BillingSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $numbers = app(DocumentNumberGenerator::class);
            foreach (Branch::query()->whereIn('code', ['DXB', 'SHJ'])->get() as $branch) {
                $userId = (int) DB::table('branch_user')->where('branch_id', $branch->id)->orderBy('user_id')->value('user_id');
                $workOrders = DB::table('work_orders')->where('branch_id', $branch->id)->orderBy('id')->pluck('id');
                $vendorId = DB::table('customers')->join('customer_role_assignments', function ($join) use ($branch): void {
                    $join->on('customer_role_assignments.customer_id', '=', 'customers.id')
                        ->where('customer_role_assignments.branch_id', $branch->id)
                        ->where('customer_role_assignments.role', 'vendor');
                })->where('customers.branch_id', $branch->id)->orderBy('customers.id')->value('customers.id');
                if (! $userId || ! $workOrders->count() || ! $vendorId) {
                    continue;
                }

                $now = now();
                $quotationId = DB::table('quotations')->where('branch_id', $branch->id)->where('title', 'Seeded AC maintenance quotation')->value('id');
                if (! $quotationId) {
                    $quotationNo = $numbers->next($branch, 'QUOTATION', 2026);
                    $quotationId = DB::table('quotations')->insertGetId([
                        'branch_id' => $branch->id, 'quotation_no' => $quotationNo, 'work_order_id' => $workOrders[0], 'vendor_id' => $vendorId,
                        'title' => 'Seeded AC maintenance quotation', 'description' => 'Sample quotation for preventive maintenance review.', 'quotation_date' => '2026-09-22', 'valid_until' => '2026-10-22', 'status' => 'sent', 'subtotal' => 850, 'tax_amount' => 0, 'total_amount' => 850, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    DB::table('quotation_lines')->insert([
                        ['branch_id' => $branch->id, 'quotation_id' => $quotationId, 'particulars' => 'Preventive AC servicing', 'quantity' => 1, 'unit_price' => 500, 'line_total' => 500, 'created_at' => $now, 'updated_at' => $now],
                        ['branch_id' => $branch->id, 'quotation_id' => $quotationId, 'particulars' => 'Replacement filters and consumables', 'quantity' => 2, 'unit_price' => 175, 'line_total' => 350, 'created_at' => $now, 'updated_at' => $now],
                    ]);
                    DB::table('quotation_payments')->insert(['branch_id' => $branch->id, 'quotation_id' => $quotationId, 'direction' => 'outward', 'particulars' => 'Vendor advance for AC maintenance', 'amount' => 425, 'due_date' => '2026-09-30', 'payment_mode' => 'bank_transfer', 'status' => 'pending', 'terms' => 'Sample payment line', 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
                }

                $invoiceId = DB::table('invoices')->where('branch_id', $branch->id)->where('title', 'Seeded plumbing service invoice')->value('id');
                if (! $invoiceId) {
                    $invoiceId = DB::table('invoices')->insertGetId([
                        'branch_id' => $branch->id, 'invoice_no' => $numbers->next($branch, 'INVOICE', 2026), 'work_order_id' => $workOrders[1] ?? $workOrders[0], 'vendor_id' => $vendorId,
                        'title' => 'Seeded plumbing service invoice', 'description' => 'Sample invoice generated for a maintenance work order.', 'invoice_date' => '2026-09-22', 'due_date' => '2026-10-06', 'status' => 'issued', 'subtotal' => 1250, 'tax_amount' => 0, 'total_amount' => 1250, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    DB::table('invoice_lines')->insert([
                        ['branch_id' => $branch->id, 'invoice_id' => $invoiceId, 'particulars' => 'Plumbing inspection and labour', 'quantity' => 1, 'unit_price' => 900, 'line_total' => 900, 'created_at' => $now, 'updated_at' => $now],
                        ['branch_id' => $branch->id, 'invoice_id' => $invoiceId, 'particulars' => 'PVC repair materials', 'quantity' => 1, 'unit_price' => 350, 'line_total' => 350, 'created_at' => $now, 'updated_at' => $now],
                    ]);
                    DB::table('invoice_payments')->insert(['branch_id' => $branch->id, 'invoice_id' => $invoiceId, 'direction' => 'outward', 'particulars' => 'Payment due to maintenance vendor', 'amount' => 1250, 'due_date' => '2026-10-06', 'payment_mode' => 'cheque', 'status' => 'pending', 'terms' => 'Sample payment line', 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        });
    }
}
