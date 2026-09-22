<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'quotation_no', 'work_order_id', 'vendor_id', 'title', 'description', 'quotation_date', 'valid_until', 'status', 'subtotal', 'tax_amount', 'total_amount', 'created_by'];

    protected function casts(): array
    {
        return ['quotation_date' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d', 'subtotal' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total_amount' => 'decimal:2'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(QuotationPayment::class);
    }
}
