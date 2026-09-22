<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'sku', 'name', 'description', 'unit_of_measure', 'reorder_level', 'status'];

    protected function casts(): array
    {
        return ['reorder_level' => 'decimal:3'];
    }

    public function scopeForBranch($q, int $id)
    {
        return $q->where('branch_id', $id);
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }
}
