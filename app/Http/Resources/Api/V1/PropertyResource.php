<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'owner_customer_id' => $this->owner_customer_id,
            'owner' => new CustomerResource($this->whenLoaded('owner')),
            'property_code' => $this->property_code,
            'unit_number' => $this->unit_number,
            'property_type' => $this->property_type,
            'name' => $this->name,
            'building_name' => $this->building_name,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'state_or_emirate' => $this->state_or_emirate,
            'country_code' => $this->country_code,
            'area' => $this->area,
            'electricity_provider' => $this->electricity_provider,
            'electricity_account_number' => $this->electricity_account_number,
            'cooling_provider' => $this->cooling_provider,
            'cooling_account_number' => $this->cooling_account_number,
            'gas_provider' => $this->gas_provider,
            'gas_connection_type' => $this->gas_connection_type,
            'gas_connection_number' => $this->gas_connection_number,
            'utility_details' => $this->utilityDetails(),
            'status' => $this->status,
            'notes' => $this->notes,
            'metadata_json' => $this->metadata_json,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
