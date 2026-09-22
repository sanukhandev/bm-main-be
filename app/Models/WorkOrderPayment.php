<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrderPayment extends Model
{
    protected $fillable = ['branch_id', 'work_order_id', 'direction', 'category', 'particulars', 'amount', 'due_date', 'payment_mode', 'status', 'terms', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_date' => 'date:Y-m-d'];
    }
}
