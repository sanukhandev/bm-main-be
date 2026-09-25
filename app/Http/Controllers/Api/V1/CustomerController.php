<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customers\ExtractIdentityDocumentRequest;
use App\Http\Requests\Api\V1\Customers\IndexCustomerRequest;
use App\Http\Requests\Api\V1\Customers\StoreCustomerRequest;
use App\Http\Requests\Api\V1\Customers\UpdateCustomerRequest;
use App\Http\Resources\Api\V1\CustomerResource;
use App\Models\Customer;
use App\Services\AuditService;
use App\Services\CustomerProfileService;
use App\Services\DocumentNumberGenerator;
use App\Services\IdentityDocumentExtractionService;
use App\Services\IdentityVerificationService;
use App\Support\Branch\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function extractIdentity(ExtractIdentityDocumentRequest $request, IdentityDocumentExtractionService $extractor, IdentityVerificationService $verification): array
    {
        Gate::authorize('create', Customer::class);

        $result = $extractor->extract($request->file('document'), $request->validated('role'));
        $result['verification_token'] = $verification->issue($result['fields']['identity_no'] ?? null, $request->user(), app(BranchContext::class)->id());

        return ['data' => $result];
    }

    public function index(IndexCustomerRequest $request, BranchContext $branchContext)
    {
        Gate::authorize('viewAny', Customer::class);

        $filters = $request->validated();
        $query = Customer::query()
            ->forBranch($branchContext->id())
            ->with('businessRoles');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $phoneSearch = preg_replace('/\D+/', '', $search) ?? '';
            $query->where(function ($query) use ($search, $phoneSearch) {
                $query->where('display_name', 'like', "%{$search}%")
                    ->orWhere('customer_code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
                if ($phoneSearch !== '') {
                    $query->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') LIKE ?", ["%{$phoneSearch}%"]);
                }
            });
        }

        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $query->when($filters['customer_type'] ?? null, fn ($query, $type) => $query->where('customer_type', $type));
        $query->when($filters['role'] ?? null, fn ($query, $role) => $query->whereHas('businessRoles', fn ($roles) => $roles->where('role', $role)));

        $sort = $filters['sort'] ?? '-created_at';
        $direction = Str::startsWith($sort, '-') ? 'desc' : 'asc';
        $query->orderBy(ltrim($sort, '-'), $direction);

        return CustomerResource::collection($query->paginate($filters['per_page'] ?? 25));
    }

    public function store(StoreCustomerRequest $request, BranchContext $branchContext, DocumentNumberGenerator $numbers, IdentityVerificationService $verification): CustomerResource
    {
        Gate::authorize('create', Customer::class);

        $data = $request->validated();
        $identityVerificationToken = $data['identity_verification_token'] ?? null;
        unset($data['identity_verification_token']);
        $roles = $data['roles'] ?? [];
        unset($data['roles']);
        $data['customer_code'] ??= $numbers->next($branchContext->branch(), in_array('owner', $roles, true) ? 'OWNER_CUSTOMER' : 'TENANT_CUSTOMER', (int) now()->format('Y'));
        $actor = $request->user();
        $customer = DB::transaction(function () use ($data, $roles, $branchContext, $identityVerificationToken, $verification, $actor): Customer {
            $customer = new Customer($data);
            $customer->forceFill([
                'branch_id' => $branchContext->id(),
                'status' => 'active',
            ])->save();
            if ($identityVerificationToken) {
                $verification->assertValid($identityVerificationToken, $customer->identity_no, $actor, $branchContext->id());
                $customer->forceFill(['identity_verified_at' => now()])->save();
            }
            $this->syncRoles($customer, $roles, $branchContext->id());
            app(AuditService::class)->record('customer.created', $customer, null, [...$customer->only(['customer_code', 'display_name', 'customer_type', 'status']), 'roles' => $roles], [], $branchContext->id());

            return $customer;
        });

        return new CustomerResource($customer->load('businessRoles'));
    }

    public function profile(Customer $customer, Request $request, CustomerProfileService $profileService): array
    {
        Gate::authorize('view', $customer);

        $customer->load('businessRoles');

        return [
            'data' => [
                'customer' => (new CustomerResource($customer))->resolve(),
                'profile' => $profileService->build($customer, $request->user()->hasPermission('accounts.view', $customer->branch_id)),
            ],
        ];
    }

    public function show(Customer $customer): CustomerResource
    {
        Gate::authorize('view', $customer);

        return new CustomerResource($customer->load('businessRoles'));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        Gate::authorize('update', $customer);
        $data = $request->validated();
        if (array_key_exists('identity_no', $data) && $customer->identity_verified_at !== null && $data['identity_no'] !== $customer->identity_no) {
            throw ValidationException::withMessages(['identity_no' => 'A verified Emirates ID cannot be modified.']);
        }
        $roles = $data['roles'] ?? null;
        unset($data['roles']);
        DB::transaction(function () use ($customer, $data, $roles): void {
            $before = [...$customer->only(['customer_code', 'display_name', 'customer_type', 'status', 'phone', 'email']), 'roles' => $customer->businessRoles()->pluck('role')->values()->all()];
            $customer->update($data);
            if ($roles !== null) {
                $this->syncRoles($customer, $roles, $customer->branch_id);
            }
            app(AuditService::class)->record('customer.updated', $customer, $before, [...$customer->only(['customer_code', 'display_name', 'customer_type', 'status', 'phone', 'email']), 'roles' => $roles ?? $before['roles']], [], $customer->branch_id);
        });

        return new CustomerResource($customer->refresh()->load('businessRoles'));
    }

    public function destroy(Customer $customer)
    {
        Gate::authorize('delete', $customer);
        $customer->forceFill(['status' => 'archived'])->save();
        $customer->delete();

        return response()->noContent();
    }

    private function syncRoles(Customer $customer, array $roles, int $branchId): void
    {
        $customer->businessRoles()->delete();
        foreach (array_unique($roles) as $role) {
            $customer->businessRoles()->create(['branch_id' => $branchId, 'role' => $role]);
        }
    }
}
