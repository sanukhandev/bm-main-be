<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MaintenanceSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();
            foreach (['DXB', 'SHJ'] as $branchCode) {
                $branchId = (int) DB::table('branches')->where('code', $branchCode)->value('id');
                $userId = (int) DB::table('branch_user')->where('branch_id', $branchId)->orderBy('user_id')->value('user_id');
                $vendors = $this->vendors($branchId, $branchCode, $now);
                $items = $this->inventory($branchId, $branchCode, $userId, $now);
                $properties = DB::table('properties')->where('branch_id', $branchId)->orderBy('id')->pluck('id');
                if ($properties->isEmpty()) {
                    continue;
                }

                $this->workOrder($branchId, $branchCode, $userId, $properties[0], $vendors[0], $items, 1, 'Air-conditioner preventive service', 'high', 'in_progress', $now);
                if ($properties->count() > 1) {
                    $this->workOrder($branchId, $branchCode, $userId, $properties[1], $vendors[1], $items, 2, 'Plumbing inspection and repair', 'normal', 'open', $now);
                }
            }
        });
    }

    private function vendors(int $branchId, string $branchCode, $now): array
    {
        $values = [
            ['code' => "VEN-{$branchCode}-001", 'name' => "{$branchCode} Cooling Services", 'phone' => '+971 50 100 2001'],
            ['code' => "VEN-{$branchCode}-002", 'name' => "{$branchCode} General Maintenance", 'phone' => '+971 50 100 2002'],
        ];

        return array_map(function (array $value) use ($branchId, $now): int {
            DB::table('customers')->updateOrInsert(
                ['branch_id' => $branchId, 'customer_code' => $value['code']],
                ['customer_type' => 'organization', 'display_name' => $value['name'], 'phone' => $value['phone'], 'status' => 'active', 'updated_at' => $now, 'created_at' => $now],
            );
            $customerId = (int) DB::table('customers')->where('branch_id', $branchId)->where('customer_code', $value['code'])->value('id');
            DB::table('customer_role_assignments')->updateOrInsert(
                ['branch_id' => $branchId, 'customer_id' => $customerId, 'role' => 'vendor'],
                ['updated_at' => $now, 'created_at' => $now],
            );

            return $customerId;
        }, $values);
    }

    private function inventory(int $branchId, string $branchCode, int $userId, $now): array
    {
        $values = [
            ['sku' => "{$branchCode}-FLT-001", 'name' => 'Air-conditioner filter', 'unit' => 'piece', 'quantity' => 20],
            ['sku' => "{$branchCode}-PLB-001", 'name' => 'PVC repair kit', 'unit' => 'box', 'quantity' => 12],
            ['sku' => "{$branchCode}-ELE-001", 'name' => 'LED bulb 12W', 'unit' => 'piece', 'quantity' => 30],
        ];

        return array_map(function (array $value) use ($branchId, $userId, $now): int {
            DB::table('inventory_items')->updateOrInsert(['branch_id' => $branchId, 'sku' => $value['sku']], ['name' => $value['name'], 'unit_of_measure' => $value['unit'], 'status' => 'active', 'updated_at' => $now, 'created_at' => $now]);
            $itemId = (int) DB::table('inventory_items')->where('branch_id', $branchId)->where('sku', $value['sku'])->value('id');
            $exists = DB::table('stock_movements')->where('branch_id', $branchId)->where('inventory_item_id', $itemId)->where('movement_type', 'opening_balance')->exists();
            if (! $exists) {
                DB::table('stock_movements')->insert(['branch_id' => $branchId, 'inventory_item_id' => $itemId, 'movement_type' => 'opening_balance', 'quantity' => $value['quantity'], 'unit_cost' => 25, 'occurred_at' => $now, 'created_by' => $userId, 'notes' => 'Seeded opening stock', 'created_at' => $now, 'updated_at' => $now]);
            }

            return $itemId;
        }, $values);
    }

    private function workOrder(int $branchId, string $branchCode, int $userId, int $propertyId, int $vendorId, array $items, int $number, string $title, string $priority, string $status, $now): void
    {
        $workOrderNo = sprintf('%s-WO-2026-%06d', $branchCode, $number);
        DB::table('work_orders')->updateOrInsert(['branch_id' => $branchId, 'work_order_no' => $workOrderNo], ['property_id' => $propertyId, 'vendor_id' => $vendorId, 'title' => $title, 'description' => 'Seeded maintenance request for review.', 'priority' => $priority, 'status' => $status, 'service_charge' => 350, 'created_by' => $userId, 'opened_at' => $now, 'completed_at' => null, 'updated_at' => $now, 'created_at' => $now]);
        $orderId = (int) DB::table('work_orders')->where('branch_id', $branchId)->where('work_order_no', $workOrderNo)->value('id');
        $this->line($branchId, $orderId, 'service', null, 'Technician service charge', 1, 350, $now);
        $this->line($branchId, $orderId, 'inventory', $items[$number - 1], 'Maintenance material used', 1, 25, $now);
    }

    private function line(int $branchId, int $orderId, string $type, ?int $itemId, string $description, int $quantity, int $unitCost, $now): void
    {
        $lineId = DB::table('work_order_lines')->where('branch_id', $branchId)->where('work_order_id', $orderId)->where('description', $description)->value('id');
        if (! $lineId) {
            $lineId = DB::table('work_order_lines')->insertGetId(['branch_id' => $branchId, 'work_order_id' => $orderId, 'line_type' => $type, 'inventory_item_id' => $itemId, 'description' => $description, 'quantity' => $quantity, 'unit_cost' => $unitCost, 'created_at' => $now, 'updated_at' => $now]);
        }
        if ($type === 'inventory' && ! DB::table('stock_movements')->where('reference_type', 'work_order')->where('reference_id', $orderId)->where('inventory_item_id', $itemId)->exists()) {
            DB::table('stock_movements')->insert(['branch_id' => $branchId, 'inventory_item_id' => $itemId, 'movement_type' => 'work_order_consumption', 'quantity' => -$quantity, 'unit_cost' => $unitCost, 'reference_type' => 'work_order', 'reference_id' => $orderId, 'occurred_at' => $now, 'created_by' => DB::table('work_orders')->where('id', $orderId)->value('created_by'), 'notes' => $description, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
