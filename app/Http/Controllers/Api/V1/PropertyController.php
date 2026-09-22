<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Properties\IndexPropertyRequest;
use App\Http\Requests\Api\V1\Properties\StorePropertyRequest;
use App\Http\Requests\Api\V1\Properties\UpdatePropertyRequest;
use App\Http\Resources\Api\V1\PropertyResource;
use App\Models\CustomerRoleAssignment;
use App\Models\Property;
use App\Support\Branch\BranchContext;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PropertyController extends Controller
{
    public function index(IndexPropertyRequest $request, BranchContext $branchContext)
    {
        Gate::authorize('viewAny', Property::class);
        $filters = $request->validated();
        $query = Property::query()->forBranch($branchContext->id())->with('owner');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($query) => $query->where('name', 'like', "%{$search}%")
                ->orWhere('property_code', 'like', "%{$search}%")
                ->orWhere('unit_number', 'like', "%{$search}%"));
        }

        $query->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value));
        $query->when($filters['property_type'] ?? null, fn ($query, $value) => $query->where('property_type', $value));
        $query->when($filters['owner_customer_id'] ?? null, fn ($query, $value) => $query->where('owner_customer_id', $value));
        $sort = $filters['sort'] ?? '-created_at';
        $query->orderBy(ltrim($sort, '-'), Str::startsWith($sort, '-') ? 'desc' : 'asc');

        return PropertyResource::collection($query->paginate($filters['per_page'] ?? 25));
    }

    public function store(StorePropertyRequest $request, BranchContext $branchContext): PropertyResource
    {
        Gate::authorize('create', Property::class);
        $data = $request->validated();
        $this->ensureOwnerRole($branchContext->id(), $data['owner_customer_id']);
        $property = new Property($data);
        $property->forceFill(['branch_id' => $branchContext->id(), 'status' => 'active'])->save();

        return new PropertyResource($property->load('owner'));
    }

    public function show(Property $property): PropertyResource
    {
        Gate::authorize('view', $property);

        return new PropertyResource($property->load('owner'));
    }

    public function update(UpdatePropertyRequest $request, Property $property, BranchContext $branchContext): PropertyResource
    {
        Gate::authorize('update', $property);
        $data = $request->validated();
        $property->update($data);

        return new PropertyResource($property->refresh()->load('owner'));
    }

    public function destroy(Property $property): Response
    {
        Gate::authorize('delete', $property);
        $property->forceFill(['status' => 'archived'])->save();

        return response()->noContent();
    }

    private function ensureOwnerRole(int $branchId, int $customerId): void
    {
        if (! CustomerRoleAssignment::query()->where('branch_id', $branchId)->where('customer_id', $customerId)->where('role', 'owner')->exists()) {
            throw new ApiException('OWNER_ROLE_REQUIRED', 'The selected customer is not an owner.', 422);
        }
    }
}
