<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'invoice_no' => $this->invoice_no, 'quotation_id' => $this->quotation_id, 'quotation' => new QuotationResource($this->whenLoaded('quotation')), 'work_order_id' => $this->work_order_id, 'work_order' => new WorkOrderResource($this->whenLoaded('workOrder')), 'vendor' => new VendorResource($this->whenLoaded('vendor')), 'title' => $this->title, 'description' => $this->description, 'invoice_date' => $this->invoice_date?->format('Y-m-d'), 'due_date' => $this->due_date?->format('Y-m-d'), 'status' => $this->status, 'subtotal' => $this->subtotal, 'tax_amount' => $this->tax_amount, 'total_amount' => $this->total_amount, 'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')), 'payments' => BillingPaymentResource::collection($this->whenLoaded('payments')), 'created_at' => $this->created_at?->toIso8601String()];
    }
}
