<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Administration\StoreUserRequest;
use App\Http\Requests\Api\V1\Administration\StoreBranchRequest;
use App\Http\Requests\Api\V1\Administration\UpdateBranchRequest;
use App\Http\Requests\Api\V1\Administration\UpdateUserRequest;
use App\Http\Requests\Api\V1\Administration\UpdateUserStatusRequest;
use App\Http\Resources\Api\V1\BranchResource;
use App\Models\Role;
use App\Models\Branch;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdministrationController extends Controller
{
    public function branches(Request $request)
    {
        $this->ensureSuperAdmin($request);

        return BranchResource::collection(Branch::query()->orderBy('name')->get());
    }

    public function storeBranch(StoreBranchRequest $request)
    {
        $data = $request->validated();
        $data['state_or_emirate'] = trim($data['state_or_emirate']);
        $branch = DB::transaction(function () use ($data) {
            $data['code'] = $this->nextBranchCode($data['state_or_emirate']);

            return Branch::query()->create($data);
        });
        app(AuditService::class)->record('branch.created', $branch, null, $branch->only(['code', 'name', 'state_or_emirate', 'status']), [], null, $request->user()->id);

        return (new BranchResource($branch))->response()->setStatusCode(201);
    }

    public function updateBranch(UpdateBranchRequest $request, Branch $branch)
    {
        $before = $branch->only(['code', 'name', 'state_or_emirate', 'status']);
        $branch->fill($request->validated())->save();
        app(AuditService::class)->record('branch.updated', $branch, $before, $branch->only(['code', 'name', 'state_or_emirate', 'status']), [], null, $request->user()->id);

        return new BranchResource($branch->fresh());
    }

    public function users(Request $request)
    {
        $this->ensureSuperAdmin($request);
        $users = User::query()->orderBy('name')->paginate(min((int) $request->query('per_page', 25), 100));
        $userIds = $users->getCollection()->modelKeys();
        $branches = DB::table('branch_user')->join('branches', 'branches.id', '=', 'branch_user.branch_id')
            ->whereIn('branch_user.user_id', $userIds)->where('branch_user.status', 'active')
            ->select('branch_user.user_id', 'branches.*')->get()->groupBy('user_id');
        $globalRoles = DB::table('user_global_roles')->join('roles', 'roles.id', '=', 'user_global_roles.role_id')
            ->whereIn('user_global_roles.user_id', $userIds)->select('user_global_roles.user_id', 'roles.key')->get()->groupBy('user_id');
        $branchRoles = DB::table('branch_user_roles')->join('roles', 'roles.id', '=', 'branch_user_roles.role_id')
            ->whereIn('branch_user_roles.user_id', $userIds)->select('branch_user_roles.user_id', 'roles.key')->get()->groupBy('user_id');

        $users->getCollection()->transform(fn (User $user) => $this->userPayload($user, $branches, $globalRoles, $branchRoles));

        return response()->json($users);
    }

    public function store(StoreUserRequest $request)
    {
        $this->ensureSuperAdmin($request);
        $data = $request->validated();

        return DB::transaction(function () use ($data, $request) {
            $roles = $this->validatedRoles($data['roles']);
            $this->ensureRoleScope($roles);
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'status' => 'active',
            ]);
            $this->syncAccess($user, $data['branch_ids'], $roles);
            app(AuditService::class)->record('user.created', $user, null, $this->accessSnapshot($user), [], null, $request->user()->id);

            return response()->json(['data' => $this->userPayload($user->fresh())], 201);
        });
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->ensureSuperAdmin($request);
        $data = $request->validated();
        $before = $this->accessSnapshot($user);

        return DB::transaction(function () use ($data, $before, $request, $user) {
            $roles = $this->validatedRoles($data['roles']);
            $this->ensureRoleScope($roles);
            $user->forceFill(array_filter([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'] ?? null,
            ], static fn ($value) => $value !== null))->save();
            $this->guardSuperAdminRemoval($user, $roles);
            $this->syncAccess($user, $data['branch_ids'], $roles);
            $after = $this->accessSnapshot($user->fresh());
            app(AuditService::class)->record('user.updated', $user, $before, $after, [], null, $request->user()->id);

            return response()->json(['data' => $this->userPayload($user->fresh())]);
        });
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user)
    {
        $this->ensureSuperAdmin($request);
        $status = $request->validated('status');
        if ($user->is($request->user()) && $status !== 'active') {
            throw new ApiException('INVALID_USER_STATUS', 'You cannot suspend or deactivate your own account.', 422);
        }
        if ($status !== 'active' && $user->isSuperAdmin() && User::query()->where('status', 'active')->where('id', '<>', $user->id)->whereHas('globalRoles', fn ($query) => $query->where('key', 'super_admin'))->doesntExist()) {
            throw new ApiException('LAST_SUPER_ADMIN', 'At least one active super admin must remain.', 422);
        }

        $before = ['status' => $user->status];
        $user->forceFill(['status' => $status])->save();
        app(AuditService::class)->record($status === 'active' ? 'user.activated' : 'user.deactivated', $user, $before, ['status' => $status], [], null, $request->user()->id);

        return response()->json(['data' => $this->userPayload($user->fresh())]);
    }

    public function roles(Request $request)
    {
        $this->ensureSuperAdmin($request);

        return response()->json(['data' => Role::query()->orderBy('name')->get()->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->key,
            'label' => $role->name,
            'description' => $role->description,
            'permissions' => [],
        ])]);
    }

    private function ensureSuperAdmin(Request $request): void
    {
        if (! $request->user()->isSuperAdmin()) {
            throw new ApiException('FORBIDDEN', 'Only super admins can access administration data.', 403);
        }
    }

    private function nextBranchCode(string $emirate): string
    {
        Branch::query()->lockForUpdate()->get('id');
        $prefix = ['Abu Dhabi' => 'AUH', 'Ajman' => 'AJM', 'Dubai' => 'DXB', 'Fujairah' => 'FUJ', 'Ras Al Khaimah' => 'RAK', 'Sharjah' => 'SHJ', 'Umm Al Quwain' => 'UAQ'][$emirate] ?? strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $emirate), 0, 3));
        $prefix = $prefix ?: 'UAE';
        $last = Branch::query()->lockForUpdate()->where('state_or_emirate', $emirate)->get('code')->reduce(function (int $max, Branch $branch) use ($prefix): int {
            return preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/i', $branch->code, $match) ? max($max, (int) $match[1]) : $max;
        }, 0);

        return sprintf('%s-%03d', $prefix, $last + 1);
    }

    private function validatedRoles(array $keys)
    {
        $roles = Role::query()->whereIn('key', $keys)->get();
        if ($roles->count() !== count(array_unique($keys))) {
            throw new ApiException('INVALID_USER_ROLES', 'One or more selected roles are invalid.', 422);
        }

        return $roles;
    }

    private function ensureRoleScope($roles): void
    {
        if ($roles->contains(fn (Role $role) => $role->scope === 'global') && ($roles->count() !== 1 || $roles->first()->key !== 'super_admin')) {
            throw new ApiException('INVALID_USER_ROLES', 'The Super Admin role cannot be combined with branch roles.', 422);
        }
        if ($roles->isEmpty()) {
            throw new ApiException('INVALID_USER_ROLES', 'At least one role is required.', 422);
        }
    }

    private function syncAccess(User $user, array $branchIds, $roles): void
    {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
        $branches = DB::table('branches')->whereIn('id', $branchIds)->where('status', 'active')->pluck('id')->all();
        if (count($branches) !== count($branchIds)) {
            throw new ApiException('INVALID_USER_BRANCHES', 'All selected branches must be active.', 422);
        }

        DB::table('branch_user_roles')->where('user_id', $user->id)->delete();
        DB::table('user_global_roles')->where('user_id', $user->id)->delete();
        DB::table('branch_user')->where('user_id', $user->id)->delete();
        $now = now();
        foreach ($branches as $index => $branchId) {
            DB::table('branch_user')->insert([
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'status' => 'active',
                'is_default' => $index === 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ($roles as $role) {
            if ($role->scope === 'global') {
                DB::table('user_global_roles')->insert(['user_id' => $user->id, 'role_id' => $role->id, 'created_at' => $now]);

                continue;
            }
            foreach ($branches as $branchId) {
                DB::table('branch_user_roles')->insert(['branch_id' => $branchId, 'user_id' => $user->id, 'role_id' => $role->id, 'created_at' => $now]);
            }
        }
    }

    private function guardSuperAdminRemoval(User $user, $roles): void
    {
        if (! $user->isSuperAdmin() || $roles->contains('key', 'super_admin')) {
            return;
        }
        if (User::query()->where('status', 'active')->where('id', '<>', $user->id)->whereHas('globalRoles', fn ($query) => $query->where('key', 'super_admin'))->doesntExist()) {
            throw new ApiException('LAST_SUPER_ADMIN', 'At least one active super admin must remain.', 422);
        }
    }

    private function accessSnapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'branch_ids' => DB::table('branch_user')->where('user_id', $user->id)->where('status', 'active')->pluck('branch_id')->sort()->values()->all(),
            'roles' => DB::table('user_global_roles')->join('roles', 'roles.id', '=', 'user_global_roles.role_id')->where('user_id', $user->id)->pluck('roles.key')->merge(DB::table('branch_user_roles')->join('roles', 'roles.id', '=', 'branch_user_roles.role_id')->where('user_id', $user->id)->pluck('roles.key'))->unique()->sort()->values()->all(),
        ];
    }

    private function userPayload(User $user, $branches = null, $globalRoles = null, $branchRoles = null): array
    {
        $branches ??= DB::table('branch_user')->join('branches', 'branches.id', '=', 'branch_user.branch_id')->where('branch_user.user_id', $user->id)->where('branch_user.status', 'active')->select('branch_user.user_id', 'branches.*')->get()->groupBy('user_id');
        $globalRoles ??= DB::table('user_global_roles')->join('roles', 'roles.id', '=', 'user_global_roles.role_id')->where('user_global_roles.user_id', $user->id)->select('user_global_roles.user_id', 'roles.key')->get()->groupBy('user_id');
        $branchRoles ??= DB::table('branch_user_roles')->join('roles', 'roles.id', '=', 'branch_user_roles.role_id')->where('branch_user_roles.user_id', $user->id)->select('branch_user_roles.user_id', 'roles.key')->get()->groupBy('user_id');
        $roles = collect($globalRoles->get($user->id, collect()))->merge($branchRoles->get($user->id, collect()))->pluck('key')->unique()->values();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $roles,
            'branches' => BranchResource::collection($branches->get($user->id, collect()))->resolve(),
            'status' => $user->status,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }
}
