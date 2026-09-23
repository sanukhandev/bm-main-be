<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\OwnerAgreement;
use App\Models\Property;
use App\Models\TenantAgreement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PropertyAvailabilityService
{
    public const BLOCKING_TENANT_STATUSES = ['pending_approval', 'approved', 'commenced', 'on_hold'];

    public const NON_BLOCKING_TENANT_STATUSES = ['draft', 'cancelled', 'terminated', 'expired'];

    public const NON_USABLE_OWNER_STATUSES = ['cancelled', 'terminated', 'expired'];

    public function lockProperties(int $branchId, array $propertyIds): array
    {
        $ids = collect($propertyIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return Property::query()->forBranch($branchId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->all();
    }

    public function assertAvailable(
        int $branchId,
        array $propertyIds,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        ?int $excludeAgreementId = null,
    ): void {
        $conflicts = [];

        foreach (array_values(array_unique(array_map('intval', $propertyIds))) as $propertyId) {
            $agreement = TenantAgreement::query()
                ->forBranch($branchId)
                ->whereIn('status', self::BLOCKING_TENANT_STATUSES)
                ->where('start_date', '<=', $endDate->toDateString())
                ->where('end_date', '>=', $startDate->toDateString())
                ->when($excludeAgreementId, fn (Builder $query) => $query->where($query->getModel()->qualifyColumn('id'), '!=', $excludeAgreementId))
                ->whereHas('properties', fn (Builder $query) => $query
                    ->where('properties.id', $propertyId)
                    ->where('tenant_agreement_properties.branch_id', $branchId))
                ->first(['id']);

            if ($agreement) {
                $conflicts[] = [
                    'property_id' => $propertyId,
                    'message' => 'Property overlaps an existing tenant agreement.',
                ];
            }
        }

        if ($conflicts !== []) {
            throw new ApiException(
                'PROPERTY_NOT_AVAILABLE',
                'One or more properties are not available for the requested period.',
                409,
                ['properties' => $conflicts],
            );
        }
    }

    public function assertOwnerCoverage(int $branchId, array $properties, CarbonImmutable $startDate, CarbonImmutable $endDate): void
    {
        foreach ($properties as $property) {
            $ownerAgreement = OwnerAgreement::query()
                ->forBranch($branchId)
                ->whereKey($property['source_owner_agreement_id'])
                ->first();

            if (! $ownerAgreement || in_array($ownerAgreement->status, self::NON_USABLE_OWNER_STATUSES, true)) {
                throw new ApiException('PROPERTY_COVERAGE_REQUIRED', 'Each tenant property must be covered by a valid source owner agreement.', 422);
            }

            $covered = DB::table('owner_agreement_properties')
                ->where('branch_id', $branchId)
                ->where('property_id', $property['property_id'])
                ->where('owner_agreement_id', $ownerAgreement->id)
                ->exists();

            if (! $covered) {
                throw new ApiException('PROPERTY_COVERAGE_REQUIRED', 'Each tenant property must be covered by its source owner agreement.', 422);
            }

            if ($startDate->lt(CarbonImmutable::parse($ownerAgreement->start_date)) || $endDate->gt(CarbonImmutable::parse($ownerAgreement->end_date))) {
                throw new ApiException('OWNER_AGREEMENT_DATE_COVERAGE_REQUIRED', 'Tenant agreement dates must be within the source owner agreement period.', 422);
            }
        }
    }

    public function availablePropertiesQuery(int $branchId, CarbonImmutable $startDate, CarbonImmutable $endDate, ?int $sourceOwnerAgreementId = null, ?int $excludeTenantAgreementId = null): Builder
    {
        $query = Property::query()->forBranch($branchId)->where('properties.status', 'active');

        $query->whereExists(function ($subquery) use ($branchId, $startDate, $endDate, $sourceOwnerAgreementId) {
            $subquery->selectRaw('1')
                ->from('owner_agreement_properties as coverage')
                ->join('owner_agreements as owners', function ($join) use ($branchId) {
                    $join->on('owners.id', '=', 'coverage.owner_agreement_id')
                        ->where('owners.branch_id', $branchId);
                })
                ->whereColumn('coverage.property_id', 'properties.id')
                ->where('coverage.branch_id', $branchId)
                ->whereNull('owners.deleted_at')
                ->where('owners.start_date', '<=', $startDate->toDateString())
                ->where('owners.end_date', '>=', $endDate->toDateString())
                ->whereNotIn('owners.status', self::NON_USABLE_OWNER_STATUSES)
                ->when($sourceOwnerAgreementId, fn ($query) => $query->where('coverage.owner_agreement_id', $sourceOwnerAgreementId));
        });

        $query->whereNotExists(function ($subquery) use ($branchId, $startDate, $endDate, $excludeTenantAgreementId) {
            $subquery->selectRaw('1')
                ->from('tenant_agreement_properties as occupancy')
                ->join('tenant_agreements as tenants', function ($join) use ($branchId) {
                    $join->on('tenants.id', '=', 'occupancy.tenant_agreement_id')
                        ->where('tenants.branch_id', $branchId);
                })
                ->whereColumn('occupancy.property_id', 'properties.id')
                ->where('occupancy.branch_id', $branchId)
                ->whereNull('tenants.deleted_at')
                ->whereIn('tenants.status', self::BLOCKING_TENANT_STATUSES)
                ->when($excludeTenantAgreementId, fn ($query) => $query->where('tenants.id', '!=', $excludeTenantAgreementId))
                ->where('tenants.start_date', '<=', $endDate->toDateString())
                ->where('tenants.end_date', '>=', $startDate->toDateString());
        });

        return $query;
    }
}
