<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'work_order_no', 'property_id', 'vendor_id', 'title', 'description', 'priority', 'status', 'service_charge', 'created_by', 'opened_at', 'completed_at'];

    protected function casts(): array
    {
        return ['service_charge' => 'decimal:2', 'opened_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines()
    {
        return $this->hasMany(WorkOrderLine::class);
    }

    public function payments()
    {
        return $this->hasMany(WorkOrderPayment::class);
    }

    public function scopeForBranch($q, int $id)
    {
        return $q->where('branch_id', $id);
    }
}
