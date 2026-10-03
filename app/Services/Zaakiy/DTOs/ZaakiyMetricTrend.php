<?php

namespace App\Services\Zaakiy\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class ZaakiyMetricTrend implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $metric,
        public string $label,
        public string $unit,
        public string $granularity,
        public array $range,
        public array $points,
        public array $summary = [],
        public array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'metric' => $this->metric,
            'label' => $this->label,
            'unit' => $this->unit,
            'granularity' => $this->granularity,
            'range' => $this->range,
            'points' => array_map(static fn ($point) => $point instanceof Arrayable ? $point->toArray() : $point, $this->points),
            'summary' => $this->summary,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
