<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgreementDispute extends Model
{
    protected $fillable = ['branch_id', 'owner_agreement_id', 'tenant_agreement_id', 'raised_by_user_id', 'subject', 'description', 'status', 'resolution'];

    public function comments(): HasMany
    {
        return $this->hasMany(AgreementDisputeComment::class);
    }
}
