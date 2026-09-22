<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'branch_id' => $this->branch_id, 'sku' => $this->sku, 'name' => $this->name, 'description' => $this->description, 'unit_of_measure' => $this->unit_of_measure, 'reorder_level' => $this->reorder_level, 'stock_on_hand' => (float) ($this->stock_on_hand ?? 0), 'status' => $this->status];
    }
}
