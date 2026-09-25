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
            'identity_no' => $this->identity_no,
            'identity_verified' => $this->identity_verified_at !== null,
            'company_registration_no' => $this->company_registration_no,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'state_or_emirate' => $this->state_or_emirate,
            'country_code' => $this->country_code,
            'notes' => $this->notes,
            'status' => $this->status,
            'roles' => $this->whenLoaded('businessRoles', fn () => $this->businessRoles->pluck('role')->values()->all()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
