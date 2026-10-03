<?php

namespace App\Services\Zaakiy\DTOs;

final readonly class ZaakiyMetricComparison
{
    public function __construct(
        public string $metric,
        public string $label,
        public int|float $currentValue,
        public int|float $comparisonValue,
        public int|float $absoluteDelta,
        public ?float $percentageDelta,
        public string $direction,
        public string $unit,
        public array $currentRange,
        public array $comparisonRange,
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
            'percentage_delta' => $this->percentageDelta,
            'direction' => $this->direction,
            'unit' => $this->unit,
            'current_range' => $this->currentRange,
            'comparison_range' => $this->comparisonRange,
            'meta' => $this->meta,
        ];
    }
}
