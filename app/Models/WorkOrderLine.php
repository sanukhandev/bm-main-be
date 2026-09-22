<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrderLine extends Model
{
    protected $fillable = ['branch_id', 'work_order_id', 'line_type', 'inventory_item_id', 'description', 'quantity', 'unit_cost'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:2'];
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
