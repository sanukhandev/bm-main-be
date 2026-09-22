<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePayment extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'invoice_id', 'direction', 'particulars', 'amount', 'due_date', 'payment_mode', 'status', 'terms', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_date' => 'date:Y-m-d'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
