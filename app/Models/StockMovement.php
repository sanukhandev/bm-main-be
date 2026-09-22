<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = ['branch_id', 'inventory_item_id', 'movement_type', 'quantity', 'unit_cost', 'reference_type', 'reference_id', 'occurred_at', 'created_by', 'notes'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:2', 'occurred_at' => 'datetime'];
    }
}
