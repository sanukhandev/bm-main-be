<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name, 'email' => $this->actor->email] : null,
            'branch' => $this->branch ? ['id' => $this->branch->id, 'name' => $this->branch->name] : null,
            'before' => $this->before_json,
            'after' => $this->after_json,
            'metadata' => $this->metadata_json,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
