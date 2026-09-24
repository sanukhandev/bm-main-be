<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationPayment extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'quotation_id', 'direction', 'particulars', 'amount', 'due_date', 'payment_mode', 'cheque_no', 'cheque_date', 'bank_name', 'bank_reference', 'transfer_date', 'status', 'terms', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_date' => 'date:Y-m-d', 'cheque_date' => 'date:Y-m-d', 'transfer_date' => 'date:Y-m-d'];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function accountTransaction()
    {
        return $this->hasOne(AccountTransaction::class, 'source_id')->where('source_type', 'quotation_payment');
    }
}
