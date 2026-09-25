<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class VendorResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'branch_id' => $this->branch_id, 'name' => $this->display_name, 'phone' => $this->phone, 'email' => $this->email, 'status' => $this->status, 'created_at' => $this->created_at?->toIso8601String()];
    }
}
