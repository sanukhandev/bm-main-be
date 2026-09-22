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
            'branches' => BranchResource::collection($this->accessibleBranches()->get()),
        ];
    }

    private function globalRoleKeys(): array
    {
        return $this->globalRoles()->pluck('key')->values()->all();
    }
}
