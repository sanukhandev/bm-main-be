<?php

namespace App\Services\Zaakiy\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class ZaakiyFilterCapability implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $id,
        public string $type,
        public array $allowedValues = [],
        public array $requiresPermissions = [],
        public bool $supportsContextInheritance = true,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'allowed_values' => $this->allowedValues,
            'requires_permissions' => $this->requiresPermissions,
            'supports_context_inheritance' => $this->supportsContextInheritance,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
