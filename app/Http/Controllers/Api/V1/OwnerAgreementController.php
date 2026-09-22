<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Agreements\DeleteAgreementRequest;
use App\Http\Requests\Api\V1\Agreements\IndexAgreementRequest;
use App\Http\Requests\Api\V1\Agreements\StoreOwnerAgreementRequest;
use App\Http\Requests\Api\V1\Agreements\UpdateOwnerAgreementRequest;
use App\Http\Resources\Api\V1\OwnerAgreementResource;
use App\Models\CustomerRoleAssignment;
use App\Models\OwnerAgreement;
use App\Services\AgreementScheduleService;
use App\Services\DocumentNumberGenerator;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class OwnerAgreementController extends Controller
{
    public function index(IndexAgreementRequest $request, BranchContext $branchContext)
    {
        Gate::authorize('viewAny', OwnerAgreement::class);
        $filters = $request->validated();
        $query = OwnerAgreement::query()->forBranch($branchContext->id())->with(['owner', 'properties']);
        $this->applyFilters($query, $filters, 'owner_customer_id');

        return OwnerAgreementResource::collection($query->paginate($filters['per_page'] ?? 25));
    }

    public function store(StoreOwnerAgreementRequest $request, BranchContext $branchContext, DocumentNumberGenerator $numbers, AgreementScheduleService $schedules): OwnerAgreementResource
    {
        Gate::authorize('create', OwnerAgreement::class);
        $data = $request->validated();
        $this->ensureRole($branchContext->id(), $data['owner_customer_id'], 'owner', 'OWNER_ROLE_REQUIRED');
        $this->ensurePropertiesBelongToOwner($branchContext->id(), $data['property_ids'], $data['owner_customer_id']);

        $agreement = DB::transaction(function () use ($data, $branchContext, $numbers, $schedules) {
            $propertyIds = $data['property_ids'];
            unset($data['property_ids']);
            $data['agreement_no'] ??= $numbers->next($branchContext->branch(), 'OWNER_AGREEMENT', (int) date('Y', strtotime($data['start_date'])));
            $agreement = new OwnerAgreement($data);
            $agreement->forceFill(['branch_id' => $branchContext->id(), 'status' => 'draft'])->save();
            $agreement->properties()->attach($propertyIds, [
                'branch_id' => $branchContext->id(),
                'owner_customer_id' => $agreement->owner_customer_id,
            ]);
            $schedules->create('owner', $agreement->id, $branchContext->id(), $agreement->start_date->format('Y-m-d'), $agreement->payment_count, $agreement->total_amount, $agreement->payment_frequency ?: 'monthly', $agreement->payment_mode);

            return $agreement;
        });

        return new OwnerAgreementResource($agreement->load(['owner', 'properties']));
    }

    public function show(OwnerAgreement $ownerAgreement): OwnerAgreementResource
    {
        Gate::authorize('view', $ownerAgreement);

        return new OwnerAgreementResource($ownerAgreement->load(['owner', 'properties', 'installments']));
    }

    public function update(UpdateOwnerAgreementRequest $request, OwnerAgreement $ownerAgreement, BranchContext $branchContext): OwnerAgreementResource
    {
        Gate::authorize('update', $ownerAgreement);
        $data = $request->validated();
        if (isset($data['owner_customer_id'])) {
            $this->ensureRole($branchContext->id(), $data['owner_customer_id'], 'owner', 'OWNER_ROLE_REQUIRED');
        }
        if (isset($data['property_ids']) || isset($data['owner_customer_id'])) {
            $propertyIds = $data['property_ids'] ?? $ownerAgreement->properties()->pluck('properties.id')->all();
            $this->ensurePropertiesBelongToOwner($branchContext->id(), $propertyIds, $data['owner_customer_id'] ?? $ownerAgreement->owner_customer_id);
        }

        DB::transaction(function () use ($data, $ownerAgreement, $branchContext) {
            $propertyIds = $data['property_ids'] ?? null;
            unset($data['property_ids']);
            $ownerAgreement->update($data);
            if ($propertyIds !== null) {
                $ownerAgreement->properties()->syncWithPivotValues($propertyIds, [
                    'branch_id' => $branchContext->id(),
                    'owner_customer_id' => $ownerAgreement->owner_customer_id,
                ]);
            }
            $ownerAgreement->increment('lock_version');
        });

        return new OwnerAgreementResource($ownerAgreement->refresh()->load(['owner', 'properties']));
    }

    public function destroy(DeleteAgreementRequest $request, OwnerAgreement $ownerAgreement): OwnerAgreementResource
    {
        Gate::authorize('delete', $ownerAgreement);
        if ($ownerAgreement->status === 'terminated') {
            throw new ApiException('RESOURCE_CONFLICT', 'The agreement is already terminated.', 409);
        }

        DB::transaction(function () use ($request, $ownerAgreement) {
            $fromStatus = $ownerAgreement->status;
            $ownerAgreement->forceFill([
                'status' => 'terminated',
                'terminated_at' => now(),
                'terminated_by_user_id' => $request->user()->getAuthIdentifier(),
                'termination_reason' => $request->validated()['reason'] ?? 'Terminated through API.',
            ])->save();
            $ownerAgreement->delete();
            $ownerAgreement->statusHistory()->create([
                'branch_id' => $ownerAgreement->branch_id,
                'from_status' => $fromStatus,
                'to_status' => 'terminated',
                'action' => 'terminated',
                'changed_by_user_id' => $request->user()->getAuthIdentifier(),
                'reason' => $request->validated()['reason'] ?? null,
            ]);
        });

        return new OwnerAgreementResource($ownerAgreement->refresh()->load(['owner', 'properties']));
    }

    private function applyFilters($query, array $filters, string $partyColumn): void
    {
        $query->when($filters['search'] ?? null, fn ($query, $value) => $query->where('agreement_no', 'like', "%{$value}%"));
        $query->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value));
        $query->when($filters['party_customer_id'] ?? null, fn ($query, $value) => $query->where($partyColumn, $value));
        $sort = $filters['sort'] ?? '-created_at';
        $query->orderBy(ltrim($sort, '-'), Str::startsWith($sort, '-') ? 'desc' : 'asc');
    }

    private function ensureRole(int $branchId, int $customerId, string $role, string $code): void
    {
        if (! CustomerRoleAssignment::query()->where('branch_id', $branchId)->where('customer_id', $customerId)->where('role', $role)->exists()) {
            throw new ApiException($code, "The selected customer is not a {$role}.", 422);
        }
    }

    private function ensurePropertiesBelongToOwner(int $branchId, array $propertyIds, int $ownerCustomerId): void
    {
        $count = DB::table('properties')
            ->where('branch_id', $branchId)
            ->where('owner_customer_id', $ownerCustomerId)
            ->whereIn('id', $propertyIds)
            ->count();

        if ($count !== count(array_unique($propertyIds))) {
            throw new ApiException('PROPERTY_OWNER_MISMATCH', 'Every property must belong to the agreement owner.', 422);
        }
    }
}
