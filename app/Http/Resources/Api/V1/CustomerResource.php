<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'customer_code' => $this->customer_code,
            'customer_type' => $this->customer_type,
            'display_name' => $this->display_name,
            'legal_name' => $this->legal_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'tax_registration_no' => $this->tax_registration_no,
            'status' => $this->status,
            'roles' => $this->whenLoaded('businessRoles', fn () => $this->businessRoles->pluck('role')->values()->all()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
