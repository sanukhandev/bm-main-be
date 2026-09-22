<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationLine extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'quotation_id', 'particulars', 'quantity', 'unit_price', 'line_total'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
