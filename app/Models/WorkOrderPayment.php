<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrderPayment extends Model
{
    protected $fillable = ['branch_id', 'work_order_id', 'direction', 'category', 'particulars', 'amount', 'due_date', 'payment_mode', 'cheque_no', 'cheque_date', 'bank_name', 'bank_reference', 'transfer_date', 'status', 'terms', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_date' => 'date:Y-m-d', 'cheque_date' => 'date:Y-m-d', 'transfer_date' => 'date:Y-m-d'];
    }
}
