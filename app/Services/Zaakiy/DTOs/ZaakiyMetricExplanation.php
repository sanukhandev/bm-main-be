<?php

namespace App\Services\Zaakiy\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class ZaakiyMetricExplanation implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $metric,
        public string $label,
        public int|float $currentValue,
        public int|float $comparisonValue,
        public int|float $absoluteDelta,
        public array $currentRange,
        public array $comparisonRange,
        public array $drivers = [],
        public int|float $residualDelta = 0,
        public ?float $coveragePercentage = null,
        public string $type = 'additive_contribution',
        public array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'metric' => $this->metric,
            'label' => $this->label,
            'current_value' => $this->currentValue,
            'comparison_value' => $this->comparisonValue,
            'absolute_delta' => $this->absoluteDelta,
            'current_range' => $this->currentRange,
            'comparison_range' => $this->comparisonRange,
            'drivers' => array_map(static fn ($driver) => $driver instanceof Arrayable ? $driver->toArray() : $driver, $this->drivers),
            'residual_delta' => $this->residualDelta,
            'coverage_percentage' => $this->coveragePercentage,
            'type' => $this->type,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
