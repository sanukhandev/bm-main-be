<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgreementDisputeComment extends Model
{
    protected $fillable = ['branch_id', 'agreement_dispute_id', 'user_id', 'comment'];
}
