<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OwnerAgreementStatusHistory extends Model
{
    protected $table = 'owner_agreement_status_history';

    public $timestamps = false;

    protected $fillable = ['branch_id', 'from_status', 'to_status', 'action', 'changed_by_user_id', 'reason', 'metadata_json'];

    protected function casts(): array
    {
        return ['metadata_json' => 'array', 'created_at' => 'datetime'];
    }
}
