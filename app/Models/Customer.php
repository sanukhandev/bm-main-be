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
        'phone_numbers_json',
        'representative_json',
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
            'phone_numbers_json' => 'array',
            'representative_json' => 'array',
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

    public function phoneNumbers(): array
    {
        if (is_array($this->phone_numbers_json) && count($this->phone_numbers_json) > 0) {
            return $this->phone_numbers_json;
        }

        return $this->phone ? [['type' => 'contact', 'number' => $this->phone]] : [];
    }
}
