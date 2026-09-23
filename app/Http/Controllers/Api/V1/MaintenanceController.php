<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateWorkOrder;
use App\Actions\PostWorkOrderPayment;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Maintenance\StoreInventoryItemRequest;
use App\Http\Requests\Api\V1\Maintenance\StoreVendorRequest;
use App\Http\Requests\Api\V1\Maintenance\StoreWorkOrderPaymentRequest;
use App\Http\Requests\Api\V1\Maintenance\StoreWorkOrderRequest;
use App\Http\Requests\Api\V1\Maintenance\UpdateMaintenanceStatusRequest;
use App\Http\Requests\Api\V1\Maintenance\UpdateWorkOrderPaymentStatusRequest;
use App\Http\Requests\Api\V1\Maintenance\UpdateWorkOrderRequest;
use App\Http\Resources\Api\V1\InventoryItemResource;
use App\Http\Resources\Api\V1\VendorResource;
use App\Http\Resources\Api\V1\WorkOrderPaymentResource;
use App\Http\Resources\Api\V1\WorkOrderResource;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderPayment;
use App\Services\PaymentModeDetails;
use App\Support\Branch\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaintenanceController extends Controller
{
    public function vendors(Request $request, BranchContext $context)
    {
        return VendorResource::collection(Vendor::query()->forBranch($context->id())->when($request->query('search'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))->latest()->paginate(25));
    }

    public function storeVendor(StoreVendorRequest $request, BranchContext $context)
    {
        $vendor = Vendor::query()->create(['branch_id' => $context->id(), ...$request->validated()]);

        return new VendorResource($vendor);
    }

    public function updateVendor(StoreVendorRequest $request, int $vendor, BranchContext $context)
    {
        $record = Vendor::query()->forBranch($context->id())->findOrFail($vendor);
        $record->update($request->validated());

        return new VendorResource($record->refresh());
    }

    public function deleteVendor(int $vendor, BranchContext $context)
    {
        Vendor::query()->forBranch($context->id())->findOrFail($vendor)->update(['status' => 'inactive']);

        return response()->noContent();
    }

    public function inventory(Request $request, BranchContext $context)
    {
        $query = InventoryItem::query()->forBranch($context->id())->select('inventory_items.*')->selectSub(DB::table('stock_movements')->selectRaw('COALESCE(SUM(quantity), 0)')->whereColumn('inventory_item_id', 'inventory_items.id')->where('branch_id', $context->id()), 'stock_on_hand')->when($request->query('search'), fn ($q, $v) => $q->where(fn ($x) => $x->where('name', 'like', "%{$v}%")->orWhere('sku', 'like', "%{$v}%")))->latest();

        return InventoryItemResource::collection($query->paginate(25));
    }

    public function storeInventory(StoreInventoryItemRequest $request, BranchContext $context)
    {
        $data = $request->validated();
        $opening = (float) ($data['opening_quantity'] ?? 0);
        unset($data['opening_quantity']);
        $item = DB::transaction(function () use ($context, $data, $opening, $request) {
            $item = InventoryItem::query()->create(['branch_id' => $context->id(), ...$data]);
            if ($opening > 0) {
                StockMovement::query()->create(['branch_id' => $context->id(), 'inventory_item_id' => $item->id, 'movement_type' => 'opening_balance', 'quantity' => $opening, 'unit_cost' => 0, 'occurred_at' => now(), 'created_by' => $request->user()->getAuthIdentifier(), 'notes' => 'Opening inventory']);
            }

            return $item;
        });

        return new InventoryItemResource($item);
    }

    public function updateInventory(StoreInventoryItemRequest $request, int $item, BranchContext $context)
    {
        $record = InventoryItem::query()->forBranch($context->id())->findOrFail($item);
        $record->update($request->validated());

        return new InventoryItemResource($record->refresh());
    }

    public function deleteInventory(int $item, BranchContext $context)
    {
        InventoryItem::query()->forBranch($context->id())->findOrFail($item)->update(['status' => 'inactive']);

        return response()->noContent();
    }

    public function workOrders(Request $request, BranchContext $context)
    {
        $query = WorkOrder::query()->forBranch($context->id())->with(['property', 'vendor'])->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))->when($request->query('search'), fn ($q, $v) => $q->where(fn ($x) => $x->where('work_order_no', 'like', "%{$v}%")->orWhere('title', 'like', "%{$v}%")))->latest();

        return WorkOrderResource::collection($query->paginate(25));
    }

    public function showWorkOrder(int $workOrder, BranchContext $context)
    {
        return new WorkOrderResource(WorkOrder::query()->forBranch($context->id())->with(['property', 'vendor', 'lines.inventoryItem', 'payments'])->findOrFail($workOrder));
    }

    public function storeWorkOrderPayment(StoreWorkOrderPaymentRequest $request, int $workOrder, BranchContext $context)
    {
        WorkOrder::query()->forBranch($context->id())->findOrFail($workOrder);
        $payment = WorkOrderPayment::query()->create(['branch_id' => $context->id(), 'work_order_id' => $workOrder, 'created_by' => $request->user()->getAuthIdentifier(), ...PaymentModeDetails::normalize($request->validated())]);

        return new WorkOrderPaymentResource($payment);
    }

    public function updateWorkOrderPaymentStatus(UpdateWorkOrderPaymentStatusRequest $request, int $workOrder, int $payment, BranchContext $context, PostWorkOrderPayment $action)
    {
        WorkOrder::query()->forBranch($context->id())->findOrFail($workOrder);
        $line = WorkOrderPayment::query()->where('branch_id', $context->id())->where('work_order_id', $workOrder)->findOrFail($payment);
        if ($line->status === 'paid' && $request->validated('status') !== 'paid') {
            throw new ApiException('FINANCIAL_RECORD_IMMUTABLE', 'Posted payment lines must be voided through their financial transaction.', 409);
        }
        if ($request->validated('status') === 'paid') {
            return ['data' => $action->execute($line, $context->branch(), $request->user()->getAuthIdentifier(), $request->header('Idempotency-Key'))];
        }
        $line->update(['status' => $request->validated('status')]);

        return new WorkOrderPaymentResource($line->refresh());
    }

    public function updateWorkOrder(UpdateWorkOrderRequest $request, int $workOrder, BranchContext $context)
    {
        $record = WorkOrder::query()->forBranch($context->id())->findOrFail($workOrder);
        $data = $request->validated();
        $data['completed_at'] = $data['status'] === 'completed' ? ($record->completed_at ?: now()) : null;
        $record->update($data);

        return new WorkOrderResource($record->refresh()->load(['property', 'vendor', 'lines.inventoryItem']));
    }

    public function storeWorkOrder(StoreWorkOrderRequest $request, BranchContext $context, CreateWorkOrder $action)
    {
        return new WorkOrderResource($action->execute($context->branch(), $request->validated(), $request->user()->getAuthIdentifier()));
    }

    public function status(UpdateMaintenanceStatusRequest $request, int $workOrder, BranchContext $context)
    {
        $record = WorkOrder::query()->forBranch($context->id())->findOrFail($workOrder);
        $status = $request->validated('status');
        $record->update(['status' => $status, 'completed_at' => $status === 'completed' ? now() : null]);

        return new WorkOrderResource($record->refresh()->load(['property', 'vendor', 'lines.inventoryItem']));
    }
}
