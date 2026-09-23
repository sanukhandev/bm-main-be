<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantAgreementInstallment extends Model
{
    protected $fillable = ['installment_no', 'due_date', 'amount', 'payment_mode', 'notes'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];
    }

    public function allocations()
    {
        return $this->hasMany(AccountTransactionAllocation::class, 'tenant_agreement_installment_id');
    }
}
