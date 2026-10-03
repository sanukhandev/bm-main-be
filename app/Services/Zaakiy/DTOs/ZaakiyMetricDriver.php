<?php

namespace App\Services\Zaakiy\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class ZaakiyMetricDriver implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $dimension,
        public string $key,
        public string $label,
        public int|float $currentValue,
        public int|float $comparisonValue,
        public int|float $absoluteDelta,
        public string $direction,
        public array $references = [],
        public array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'dimension' => $this->dimension,
            'key' => $this->key,
            'label' => $this->label,
            'current_value' => $this->currentValue,
            'comparison_value' => $this->comparisonValue,
            'absolute_delta' => $this->absoluteDelta,
            'direction' => $this->direction,
            'references' => $this->references,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
