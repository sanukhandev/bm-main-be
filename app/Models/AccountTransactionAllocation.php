<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountTransactionAllocation extends Model
{
    protected $fillable = [
        'branch_id', 'account_transaction_id', 'owner_agreement_installment_id',
        'tenant_agreement_installment_id', 'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function transaction()
    {
        return $this->belongsTo(AccountTransaction::class, 'account_transaction_id');
    }
}
