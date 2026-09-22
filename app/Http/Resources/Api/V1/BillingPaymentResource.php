<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class BillingPaymentResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'direction' => $this->direction, 'particulars' => $this->particulars, 'amount' => $this->amount, 'due_date' => $this->due_date?->format('Y-m-d'), 'payment_mode' => $this->payment_mode, 'status' => $this->status, 'terms' => $this->terms, 'created_at' => $this->created_at?->toIso8601String()];
    }
}
