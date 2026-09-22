<?php

namespace App\Models;

use App\Enums\PaymentDirection;
use App\Enums\PaymentMode;
use App\Enums\PostingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountTransaction extends Model
{
    protected $fillable = [
        'branch_id', 'document_no', 'direction', 'transaction_date', 'payment_mode', 'amount',
        'party_customer_id', 'source_type', 'source_id', 'payment_sequence', 'remarks',
        'cheque_no', 'cheque_date', 'bank_name', 'bank_reference', 'transfer_date',
        'status', 'created_by', 'posted_by', 'posted_at', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'direction' => PaymentDirection::class,
            'payment_mode' => PaymentMode::class,
            'status' => PostingStatus::class,
            'transaction_date' => 'date:Y-m-d',
            'cheque_date' => 'date:Y-m-d',
            'transfer_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'party_customer_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(AccountTransactionAllocation::class);
    }
}
