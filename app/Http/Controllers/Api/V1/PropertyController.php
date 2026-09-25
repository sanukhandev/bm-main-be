<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PropertyType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Properties\AvailablePropertyRequest;
use App\Http\Requests\Api\V1\Properties\IndexPropertyRequest;
use App\Http\Requests\Api\V1\Properties\StorePropertyRequest;
use App\Http\Requests\Api\V1\Properties\UpdatePropertyRequest;
use App\Http\Resources\Api\V1\PropertyResource;
use App\Models\Branch;
use App\Models\CustomerRoleAssignment;
use App\Models\Property;
use App\Services\AuditService;
use App\Services\PropertyAvailabilityService;
use App\Services\PropertyProfileService;
use App\Support\Branch\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
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

    public function available(AvailablePropertyRequest $request, BranchContext $branchContext, PropertyAvailabilityService $availability)
    {
        Gate::authorize('viewAny', Property::class);
        $filters = $request->validated();
        $query = $availability->availablePropertiesQuery(
            $branchContext->id(),
            CarbonImmutable::parse($filters['start_date']),
            CarbonImmutable::parse($filters['end_date']),
            $filters['source_owner_agreement_id'] ?? null,
            $filters['exclude_tenant_agreement_id'] ?? null,
        )->with('owner');

        $query->when($filters['property_type'] ?? null, fn ($query, $value) => $query->where('property_type', $value));
        $query->when($filters['owner_customer_id'] ?? null, fn ($query, $value) => $query->where('owner_customer_id', $value));
        $query->when($filters['search'] ?? null, fn ($query, $value) => $query->where(fn ($query) => $query
            ->where('name', 'like', "%{$value}%")
            ->orWhere('property_code', 'like', "%{$value}%")
            ->orWhere('unit_number', 'like', "%{$value}%")));

        return PropertyResource::collection($query->orderBy('name')->paginate($filters['per_page'] ?? 25));
    }

    public function store(StorePropertyRequest $request, BranchContext $branchContext): PropertyResource
    {
        Gate::authorize('create', Property::class);
        $data = $request->validated();
        $this->ensureOwnerRole($branchContext->id(), $data['owner_customer_id']);
        $data['property_code'] ??= $this->propertyCode($branchContext->branch(), $data);
        $property = new Property($data);
        $property->forceFill(['branch_id' => $branchContext->id(), 'status' => 'active'])->save();
        app(AuditService::class)->record('property.created', $property, null, $property->only(['owner_customer_id', 'property_code', 'unit_number', 'property_type', 'name', 'building_name', 'state_or_emirate', 'area', 'status']), [], $branchContext->id());

        return new PropertyResource($property->load('owner'));
    }

    private function propertyCode(Branch $branch, array $data): string
    {
        $type = $data['property_type'] instanceof PropertyType ? $data['property_type']->value : (string) $data['property_type'];
        $typeCode = [
            'apartment' => 'APT', 'villa' => 'VIL', 'shop' => 'SHP', 'office' => 'OFF',
            'space' => 'SPC', 'labor_camp' => 'LC', 'warehouse' => 'WH', 'land' => 'LND',
        ][$type] ?? strtoupper(substr($type, 0, 3));
        $emirate = strtoupper(trim((string) ($data['state_or_emirate'] ?? '')));
        $emirateCode = [
            'DUBAI' => 'DXB', 'ABU DHABI' => 'AUH', 'SHARJAH' => 'SHJ', 'AJMAN' => 'AJM',
            'UMM AL QUWAIN' => 'UAQ', 'RAS AL KHAIMAH' => 'RAK', 'FUJAIRAH' => 'FUJ',
        ][$emirate] ?? strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $emirate) ?: 'UAE', 0, 3));
        $parts = [$branch->code, $emirateCode, $data['building_name'] ?? null, $data['unit_number'] ?? null, $typeCode];

        return implode('-', array_values(array_filter(array_map(function ($part): string {
            return strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $part), '-'));
        }, $parts))));
    }

    public function show(Property $property): PropertyResource
    {
        Gate::authorize('view', $property);

        return new PropertyResource($property->load('owner'));
    }

    public function profile(Property $property, Request $request, PropertyProfileService $profileService): array
    {
        Gate::authorize('view', $property);

        return [
            'data' => [
                'property' => (new PropertyResource($property->load('owner')))->resolve(),
                'profile' => $profileService->build($property, $request->user()->hasPermission('accounts.view', $property->branch_id)),
            ],
        ];
    }

    public function update(UpdatePropertyRequest $request, Property $property, BranchContext $branchContext): PropertyResource
    {
        Gate::authorize('update', $property);
        $data = $request->validated();
        $before = $property->only(['owner_customer_id', 'property_code', 'unit_number', 'property_type', 'name', 'building_name', 'state_or_emirate', 'area', 'status']);
        $property->update($data);
        app(AuditService::class)->record('property.updated', $property, $before, $property->only(['owner_customer_id', 'property_code', 'unit_number', 'property_type', 'name', 'building_name', 'state_or_emirate', 'area', 'status']), [], $property->branch_id);

        return new PropertyResource($property->refresh()->load('owner'));
    }

    public function destroy(Property $property): Response
    {
        Gate::authorize('delete', $property);
        $property->forceFill(['status' => 'archived'])->save();
        $property->delete();

        return response()->noContent();
    }

    private function ensureOwnerRole(int $branchId, int $customerId): void
    {
        if (! CustomerRoleAssignment::query()->where('branch_id', $branchId)->where('customer_id', $customerId)->where('role', 'owner')->exists()) {
            throw new ApiException('OWNER_ROLE_REQUIRED', 'The selected customer is not an owner.', 422);
        }
    }
}
