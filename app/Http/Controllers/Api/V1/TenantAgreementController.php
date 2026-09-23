<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Agreements\DeleteAgreementRequest;
use App\Http\Requests\Api\V1\Agreements\IndexAgreementRequest;
use App\Http\Requests\Api\V1\Agreements\StoreTenantAgreementRequest;
use App\Http\Requests\Api\V1\Agreements\UpdateTenantAgreementRequest;
use App\Http\Resources\Api\V1\TenantAgreementResource;
use App\Models\CustomerRoleAssignment;
use App\Models\TenantAgreement;
use App\Services\AgreementLifecycleService;
use App\Services\AgreementScheduleService;
use App\Services\DocumentNumberGenerator;
use App\Services\PropertyAvailabilityService;
use App\Support\Branch\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class TenantAgreementController extends Controller
{
    public function index(IndexAgreementRequest $request, BranchContext $branchContext)
    {
        Gate::authorize('viewAny', TenantAgreement::class);
        $filters = $request->validated();
        $query = TenantAgreement::query()->forBranch($branchContext->id())->with(['tenant', 'properties']);
        $this->applyFilters($query, $filters);

        return TenantAgreementResource::collection($query->paginate($filters['per_page'] ?? 25));
    }

    public function store(StoreTenantAgreementRequest $request, BranchContext $branchContext, DocumentNumberGenerator $numbers, AgreementScheduleService $schedules, PropertyAvailabilityService $availability): TenantAgreementResource
    {
        Gate::authorize('create', TenantAgreement::class);
        $data = $request->validated();
        $this->ensureTenantRole($branchContext->id(), $data['tenant_customer_id']);
        $agreement = DB::transaction(function () use ($data, $branchContext, $numbers, $schedules, $availability) {
            $properties = $data['properties'];
            $startDate = CarbonImmutable::parse($data['start_date']);
            $endDate = CarbonImmutable::parse($data['end_date']);
            $lockedProperties = $availability->lockProperties($branchContext->id(), array_column($properties, 'property_id'));
            if (count($lockedProperties) !== count($properties)) {
                throw new ApiException('PROPERTY_NOT_FOUND', 'One or more selected properties are not available in the active branch.', 404);
            }
            $availability->assertOwnerCoverage($branchContext->id(), $properties, $startDate, $endDate);
            $availability->assertAvailable($branchContext->id(), array_column($properties, 'property_id'), $startDate, $endDate);
            unset($data['properties']);
            $data['agreement_no'] ??= $numbers->next($branchContext->branch(), 'TENANT_AGREEMENT', (int) date('Y', strtotime($data['start_date'])));
            $agreement = new TenantAgreement($data);
            $agreement->forceFill(['branch_id' => $branchContext->id(), 'status' => 'draft'])->save();
            foreach ($properties as $property) {
                $agreement->properties()->attach($property['property_id'], [
                    'branch_id' => $branchContext->id(),
                    'source_owner_agreement_id' => $property['source_owner_agreement_id'],
                ]);
            }
            $schedules->create('tenant', $agreement->id, $branchContext->id(), $agreement->start_date->format('Y-m-d'), $agreement->payment_count, $agreement->total_amount, $agreement->payment_frequency ?: 'monthly', $agreement->payment_mode);

            return $agreement;
        });

        return new TenantAgreementResource($agreement->load(['tenant', 'properties']));
    }

    public function show(TenantAgreement $tenantAgreement): TenantAgreementResource
    {
        Gate::authorize('view', $tenantAgreement);

        return new TenantAgreementResource($tenantAgreement->load(['tenant', 'properties', 'installments', 'disputes.comments', 'additionalPayments']));
    }

    public function update(UpdateTenantAgreementRequest $request, TenantAgreement $tenantAgreement, BranchContext $branchContext, PropertyAvailabilityService $availability): TenantAgreementResource
    {
        Gate::authorize('update', $tenantAgreement);
        $data = $request->validated();
        if (isset($data['tenant_customer_id'])) {
            $this->ensureTenantRole($branchContext->id(), $data['tenant_customer_id']);
        }
        DB::transaction(function () use ($data, $tenantAgreement, $branchContext, $availability) {
            $properties = $data['properties'] ?? null;
            $startDate = CarbonImmutable::parse($data['start_date'] ?? $tenantAgreement->start_date);
            $endDate = CarbonImmutable::parse($data['end_date'] ?? $tenantAgreement->end_date);
            if ($properties !== null || isset($data['start_date']) || isset($data['end_date'])) {
                $properties ??= $tenantAgreement->properties->map(fn ($property) => [
                    'property_id' => $property->id,
                    'source_owner_agreement_id' => $property->pivot->source_owner_agreement_id,
                ])->all();
                $propertyIds = array_merge(
                    $tenantAgreement->properties()->pluck('properties.id')->all(),
                    array_column($properties, 'property_id'),
                );
                $lockedProperties = $availability->lockProperties($branchContext->id(), $propertyIds);
                if (count($lockedProperties) !== count(array_unique(array_map('intval', $propertyIds)))) {
                    throw new ApiException('PROPERTY_NOT_FOUND', 'One or more selected properties are not available in the active branch.', 404);
                }
                $availability->assertOwnerCoverage($branchContext->id(), $properties, $startDate, $endDate);
                $availability->assertAvailable($branchContext->id(), array_column($properties, 'property_id'), $startDate, $endDate, $tenantAgreement->id);
            }
            unset($data['properties']);
            $tenantAgreement->update($data);
            if ($properties !== null) {
                $tenantAgreement->properties()->detach();
                foreach ($properties as $property) {
                    $tenantAgreement->properties()->attach($property['property_id'], [
                        'branch_id' => $branchContext->id(),
                        'source_owner_agreement_id' => $property['source_owner_agreement_id'],
                    ]);
                }
            }
            $tenantAgreement->increment('lock_version');
        });

        return new TenantAgreementResource($tenantAgreement->refresh()->load(['tenant', 'properties']));
    }

    public function destroy(DeleteAgreementRequest $request, TenantAgreement $tenantAgreement, BranchContext $branchContext, AgreementLifecycleService $lifecycle): TenantAgreementResource
    {
        Gate::authorize('delete', $tenantAgreement);
        $action = in_array($tenantAgreement->status, ['draft', 'pending_approval', 'approved'], true) ? 'cancelled' : 'terminated';
        $tenantAgreement = $lifecycle->transition('tenant', $tenantAgreement->id, $branchContext->id(), $action, $request->validated()['reason'] ?? null, $request->user()->getAuthIdentifier());

        return new TenantAgreementResource($tenantAgreement->refresh()->load(['tenant', 'properties']));
    }

    private function applyFilters($query, array $filters): void
    {
        $query->when($filters['search'] ?? null, fn ($query, $value) => $query->where('agreement_no', 'like', "%{$value}%"));
        $query->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value));
        $query->when($filters['party_customer_id'] ?? null, fn ($query, $value) => $query->where('tenant_customer_id', $value));
        $sort = $filters['sort'] ?? '-created_at';
        $query->orderBy(ltrim($sort, '-'), Str::startsWith($sort, '-') ? 'desc' : 'asc');
    }

    private function ensureTenantRole(int $branchId, int $customerId): void
    {
        if (! CustomerRoleAssignment::query()->where('branch_id', $branchId)->where('customer_id', $customerId)->where('role', 'tenant')->exists()) {
            throw new ApiException('TENANT_ROLE_REQUIRED', 'The selected customer is not a tenant.', 422);
        }
    }
}
