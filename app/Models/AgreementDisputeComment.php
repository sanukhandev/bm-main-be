<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class AgreementDisputeComment extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'agreement_dispute_id', 'user_id', 'comment'];
}
