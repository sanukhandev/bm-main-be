<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'customer_code',
        'customer_type',
        'display_name',
        'legal_name',
        'phone',
        'email',
        'tax_registration_no',
        'identity_no',
        'company_registration_no',
        'address_line_1',
        'address_line_2',
        'city',
        'state_or_emirate',
        'country_code',
        'notes',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'identity_verified_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function businessRoles(): HasMany
    {
        return $this->hasMany(CustomerRoleAssignment::class);
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('branch_id'), $branchId);
    }
}
