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
use App\Support\Branch\BranchContext;
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

    public function store(StoreTenantAgreementRequest $request, BranchContext $branchContext): TenantAgreementResource
    {
        Gate::authorize('create', TenantAgreement::class);
        $data = $request->validated();
        $this->ensureTenantRole($branchContext->id(), $data['tenant_customer_id']);
        $this->ensureCoverage($branchContext->id(), $data['properties']);

        $agreement = DB::transaction(function () use ($data, $branchContext) {
            $properties = $data['properties'];
            unset($data['properties']);
            $agreement = new TenantAgreement($data);
            $agreement->forceFill(['branch_id' => $branchContext->id(), 'status' => 'draft'])->save();
            foreach ($properties as $property) {
                $agreement->properties()->attach($property['property_id'], [
                    'branch_id' => $branchContext->id(),
                    'source_owner_agreement_id' => $property['source_owner_agreement_id'],
                ]);
            }

            return $agreement;
        });

        return new TenantAgreementResource($agreement->load(['tenant', 'properties']));
    }

    public function show(TenantAgreement $tenantAgreement): TenantAgreementResource
    {
        Gate::authorize('view', $tenantAgreement);

        return new TenantAgreementResource($tenantAgreement->load(['tenant', 'properties']));
    }

    public function update(UpdateTenantAgreementRequest $request, TenantAgreement $tenantAgreement, BranchContext $branchContext): TenantAgreementResource
    {
        Gate::authorize('update', $tenantAgreement);
        $data = $request->validated();
        if (isset($data['tenant_customer_id'])) {
            $this->ensureTenantRole($branchContext->id(), $data['tenant_customer_id']);
        }
        if (isset($data['properties'])) {
            $this->ensureCoverage($branchContext->id(), $data['properties']);
        }

        DB::transaction(function () use ($data, $tenantAgreement, $branchContext) {
            $properties = $data['properties'] ?? null;
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

    public function destroy(DeleteAgreementRequest $request, TenantAgreement $tenantAgreement): TenantAgreementResource
    {
        Gate::authorize('delete', $tenantAgreement);
        if ($tenantAgreement->status === 'terminated') {
            throw new ApiException('RESOURCE_CONFLICT', 'The agreement is already terminated.', 409);
        }

        DB::transaction(function () use ($request, $tenantAgreement) {
            $fromStatus = $tenantAgreement->status;
            $tenantAgreement->forceFill([
                'status' => 'terminated',
                'terminated_at' => now(),
                'terminated_by_user_id' => $request->user()->getAuthIdentifier(),
                'termination_reason' => $request->validated()['reason'] ?? 'Terminated through API.',
            ])->save();
            $tenantAgreement->delete();
            $tenantAgreement->statusHistory()->create([
                'branch_id' => $tenantAgreement->branch_id,
                'from_status' => $fromStatus,
                'to_status' => 'terminated',
                'action' => 'terminated',
                'changed_by_user_id' => $request->user()->getAuthIdentifier(),
                'reason' => $request->validated()['reason'] ?? null,
            ]);
        });

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

    private function ensureCoverage(int $branchId, array $properties): void
    {
        foreach ($properties as $property) {
            $covered = DB::table('owner_agreement_properties')
                ->where('branch_id', $branchId)
                ->where('property_id', $property['property_id'])
                ->where('owner_agreement_id', $property['source_owner_agreement_id'])
                ->exists();
            if (! $covered) {
                throw new ApiException('PROPERTY_COVERAGE_REQUIRED', 'Each tenant property must be covered by its source owner agreement.', 422);
            }
        }
    }
}
