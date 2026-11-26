<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerRoleAssignment extends Model
{
    use BelongsToBranch;

    protected $table = 'customer_role_assignments';

    protected $fillable = ['branch_id', 'customer_id', 'role'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
