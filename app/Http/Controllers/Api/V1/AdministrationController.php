<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BranchResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdministrationController extends Controller
{
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

        $users->getCollection()->transform(function (User $user) use ($branches, $globalRoles, $branchRoles) {
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
        });

        return response()->json($users);
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
}
