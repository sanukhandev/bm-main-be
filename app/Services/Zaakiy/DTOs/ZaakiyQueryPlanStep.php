<?php

namespace App\Services\Zaakiy\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class ZaakiyQueryPlanStep implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $id,
        public string $type,
        public string $capability,
        public ?string $metric = null,
        public array $dependsOn = [],
        public array $input = [],
        public ?string $outputKey = null,
        public array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'capability' => $this->capability,
            'metric' => $this->metric,
            'depends_on' => $this->dependsOn,
            'input' => $this->input,
            'output_key' => $this->outputKey,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
