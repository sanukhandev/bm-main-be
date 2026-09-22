<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customers\IndexCustomerRequest;
use App\Http\Requests\Api\V1\Customers\StoreCustomerRequest;
use App\Http\Requests\Api\V1\Customers\UpdateCustomerRequest;
use App\Http\Resources\Api\V1\CustomerResource;
use App\Models\Customer;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    public function index(IndexCustomerRequest $request, BranchContext $branchContext)
    {
        Gate::authorize('viewAny', Customer::class);

        $filters = $request->validated();
        $query = Customer::query()
            ->forBranch($branchContext->id())
            ->with('businessRoles');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($query) use ($search) {
                $query->where('display_name', 'like', "%{$search}%")
                    ->orWhere('customer_code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $query->when($filters['customer_type'] ?? null, fn ($query, $type) => $query->where('customer_type', $type));

        $sort = $filters['sort'] ?? '-created_at';
        $direction = Str::startsWith($sort, '-') ? 'desc' : 'asc';
        $query->orderBy(ltrim($sort, '-'), $direction);

        return CustomerResource::collection($query->paginate($filters['per_page'] ?? 25));
    }

    public function store(StoreCustomerRequest $request, BranchContext $branchContext): CustomerResource
    {
        Gate::authorize('create', Customer::class);

        $customer = new Customer($request->validated());
        $customer->forceFill([
            'branch_id' => $branchContext->id(),
            'status' => 'active',
        ])->save();

        return new CustomerResource($customer->load('businessRoles'));
    }

    public function show(Customer $customer): CustomerResource
    {
        Gate::authorize('view', $customer);

        return new CustomerResource($customer->load('businessRoles'));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        Gate::authorize('update', $customer);
        $customer->update($request->validated());

        return new CustomerResource($customer->refresh()->load('businessRoles'));
    }
}
