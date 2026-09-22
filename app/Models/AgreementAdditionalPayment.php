<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgreementAdditionalPayment extends Model
{
    protected $fillable = ['branch_id', 'owner_agreement_id', 'tenant_agreement_id', 'direction', 'category', 'particulars', 'amount', 'due_date', 'payment_mode', 'status', 'terms', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_date' => 'date:Y-m-d'];
    }
}
