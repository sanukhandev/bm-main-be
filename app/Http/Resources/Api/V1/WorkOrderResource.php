<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        $financial = $request->user()?->hasPermission('accounts.view', (int) $this->branch_id) ?? false;

        return ['id' => $this->id, 'branch_id' => $this->branch_id, 'work_order_no' => $this->work_order_no, 'property' => new PropertyResource($this->whenLoaded('property')), 'vendor' => new VendorResource($this->whenLoaded('vendor')), 'title' => $this->title, 'description' => $this->description, 'priority' => $this->priority, 'status' => $this->status, 'service_charge' => $this->when($financial, $this->service_charge), 'lines' => $this->when($financial, $this->whenLoaded('lines')), 'payments' => $this->when($financial, WorkOrderPaymentResource::collection($this->whenLoaded('payments'))), 'opened_at' => $this->opened_at?->toIso8601String(), 'completed_at' => $this->completed_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String()];
    }
}
