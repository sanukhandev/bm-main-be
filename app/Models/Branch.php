<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'legal_name',
        'phone',
        'email',
        'address_line_1',
        'address_line_2',
        'city',
        'state_or_emirate',
        'country_code',
        'timezone',
        'currency_code',
        'status',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_user')->withPivot(['status', 'is_default'])->withTimestamps();
    }
}
