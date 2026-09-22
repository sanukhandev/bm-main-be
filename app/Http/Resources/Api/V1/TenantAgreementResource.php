<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantAgreementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'agreement_no' => $this->agreement_no,
            'tenant_customer_id' => $this->tenant_customer_id,
            'tenant' => new CustomerResource($this->whenLoaded('tenant')),
            'properties' => $this->whenLoaded('properties', fn () => $this->properties->map(fn ($property) => [
                'property_id' => $property->id,
                'source_owner_agreement_id' => $property->pivot->source_owner_agreement_id,
                'property' => new PropertyResource($property),
            ])->values()),
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'total_amount' => $this->total_amount,
            'currency_code' => $this->currency_code,
            'payment_count' => $this->payment_count,
            'payment_frequency' => $this->payment_frequency,
            'payment_mode' => $this->payment_mode,
            'terms_text' => $this->terms_text,
            'notes' => $this->notes,
            'status' => $this->status,
            'lock_version' => $this->lock_version,
            'terminated_at' => $this->terminated_at?->toIso8601String(),
            'termination_reason' => $this->termination_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
