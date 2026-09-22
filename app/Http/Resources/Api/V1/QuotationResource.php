<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class QuotationResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'quotation_no' => $this->quotation_no, 'work_order_id' => $this->work_order_id, 'work_order' => new WorkOrderResource($this->whenLoaded('workOrder')), 'vendor' => new VendorResource($this->whenLoaded('vendor')), 'title' => $this->title, 'description' => $this->description, 'quotation_date' => $this->quotation_date?->format('Y-m-d'), 'valid_until' => $this->valid_until?->format('Y-m-d'), 'status' => $this->status, 'subtotal' => $this->subtotal, 'tax_amount' => $this->tax_amount, 'total_amount' => $this->total_amount, 'lines' => QuotationLineResource::collection($this->whenLoaded('lines')), 'payments' => BillingPaymentResource::collection($this->whenLoaded('payments')), 'created_at' => $this->created_at?->toIso8601String()];
    }
}
