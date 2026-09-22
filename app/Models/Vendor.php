<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'name', 'phone', 'email', 'status'];

    public function scopeForBranch($q, int $id)
    {
        return $q->where('branch_id', $id);
    }
}
