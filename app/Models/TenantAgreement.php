<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantAgreement extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $fillable = [
        'agreement_no',
        'tenant_customer_id',
        'start_date',
        'end_date',
        'total_amount',
        'currency_code',
        'payment_count',
        'payment_frequency',
        'payment_mode',
        'terms_text',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'total_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'commenced_at' => 'datetime',
            'expired_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'tenant_customer_id');
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'tenant_agreement_properties')
            ->withPivot(['branch_id', 'source_owner_agreement_id'])
            ->withTimestamps();
    }

    public function installments(): HasMany
    {
        return $this->hasMany(TenantAgreementInstallment::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(TenantAgreementStatusHistory::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(AgreementDispute::class);
    }

    public function additionalPayments(): HasMany
    {
        return $this->hasMany(AgreementAdditionalPayment::class);
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('branch_id'), $branchId);
    }
}
