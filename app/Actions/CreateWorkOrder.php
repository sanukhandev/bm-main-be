<?php

namespace App\Actions;

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\StockMovement;
use App\Models\WorkOrder;
use App\Services\AuditService;
use App\Services\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;

class CreateWorkOrder
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    public function execute(Branch $branch, array $data, int $userId): WorkOrder
    {
        return DB::transaction(function () use ($branch, $data, $userId) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);
            $order = new WorkOrder($data);
            $order->forceFill(['branch_id' => $branch->id, 'work_order_no' => $this->numbers->next($branch, 'WORK_ORDER', (int) now()->format('Y')), 'status' => 'open', 'created_by' => $userId, 'opened_at' => now()])->save();
            foreach ($lines as $line) {
                if ($line['line_type'] === 'inventory') {
                    if (empty($line['inventory_item_id'])) {
                        throw new ApiException('INVENTORY_ITEM_REQUIRED', 'Inventory item is required for inventory lines.', 422);
                    } $available = (float) DB::table('stock_movements')->where('branch_id', $branch->id)->where('inventory_item_id', $line['inventory_item_id'])->sum(DB::raw('CASE WHEN quantity >= 0 THEN quantity ELSE quantity END'));
                    InventoryItem::query()->forBranch($branch->id)->lockForUpdate()->findOrFail($line['inventory_item_id']);
                    $out = (float) DB::table('stock_movements')->where('branch_id', $branch->id)->where('inventory_item_id', $line['inventory_item_id'])->where('quantity', '<', 0)->sum(DB::raw('ABS(quantity)'));
                    $in = (float) DB::table('stock_movements')->where('branch_id', $branch->id)->where('inventory_item_id', $line['inventory_item_id'])->where('quantity', '>', 0)->sum('quantity');
                    if ($in - $out < (float) $line['quantity']) {
                        throw new ApiException('INSUFFICIENT_STOCK', 'Inventory quantity is not available.', 422);
                    }
                } $created = $order->lines()->create(['branch_id' => $branch->id, 'line_type' => $line['line_type'], 'inventory_item_id' => $line['inventory_item_id'] ?? null, 'description' => $line['description'], 'quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost']]);
                if ($created->line_type === 'inventory') {
                    StockMovement::query()->create(['branch_id' => $branch->id, 'inventory_item_id' => $created->inventory_item_id, 'movement_type' => 'work_order_consumption', 'quantity' => 0 - (float) $created->quantity, 'unit_cost' => $created->unit_cost, 'reference_type' => 'work_order', 'reference_id' => $order->id, 'occurred_at' => now(), 'created_by' => $userId, 'notes' => $created->description]);
                }
            } app(AuditService::class)->record('work_order.created', $order, null, ['work_order_no' => $order->work_order_no, 'status' => $order->status], [], $branch->id, $userId);

            return $order->load(['property', 'vendor', 'lines.inventoryItem']);
        });
    }
}
