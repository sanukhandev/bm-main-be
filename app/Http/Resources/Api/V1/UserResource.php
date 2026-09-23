<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->globalRoleKeys(),
            'permissions' => $this->permissions(),
            'branches' => BranchResource::collection($this->accessibleBranches()->get()),
        ];
    }

    private function globalRoleKeys(): array
    {
        return $this->globalRoles()->pluck('key')->values()->all();
    }

    private function permissions(): array
    {
        if ($this->isSuperAdmin()) {
            return ['accounts.view', 'accounts.post', 'accounts.void'];
        }

        $roleIds = \DB::table('user_global_roles')->where('user_id', $this->id)->pluck('role_id')
            ->merge(\DB::table('branch_user_roles')->where('user_id', $this->id)->pluck('role_id'));

        return \DB::table('role_permissions')->whereIn('role_permissions.role_id', $roleIds)
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->pluck('permissions.key')->unique()->values()->all();
    }
}
