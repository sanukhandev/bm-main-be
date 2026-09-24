<?php

namespace App\Models;

use App\Enums\PropertyType;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $fillable = [
        'owner_customer_id',
        'property_code',
        'unit_number',
        'property_type',
        'name',
        'building_name',
        'address_line_1',
        'address_line_2',
        'city',
        'state_or_emirate',
        'country_code',
        'area',
        'notes',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'property_type' => PropertyType::class,
            'area' => 'decimal:4',
            'metadata_json' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'owner_customer_id');
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('branch_id'), $branchId);
    }
}
