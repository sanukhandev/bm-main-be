<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class QuotationLineResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'particulars' => $this->particulars, 'quantity' => $this->quantity, 'unit_price' => $this->unit_price, 'line_total' => $this->line_total];
    }
}
