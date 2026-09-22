<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchUser extends Model
{
    protected $table = 'branch_user';

    protected $fillable = ['branch_id', 'user_id', 'status', 'is_default'];
}
