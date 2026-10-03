<?php

namespace App\Services\Zaakiy\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class ZaakiyMetricAnomaly implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $code,
        public string $metric,
        public string $label,
        public string $severity,
        public int|float|null $observedValue = null,
        public int|float|null $baselineValue = null,
        public int|float|null $absoluteDelta = null,
        public ?float $percentageDelta = null,
        public array $threshold = [],
        public ?array $period = null,
        public ?array $comparisonPeriod = null,
        public string $rule = '',
        public array $references = [],
        public array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'metric' => $this->metric,
            'label' => $this->label,
            'severity' => $this->severity,
            'observed_value' => $this->observedValue,
            'baseline_value' => $this->baselineValue,
            'absolute_delta' => $this->absoluteDelta,
            'percentage_delta' => $this->percentageDelta,
            'threshold' => $this->threshold,
            'period' => $this->period,
            'comparison_period' => $this->comparisonPeriod,
            'rule' => $this->rule,
            'references' => $this->references,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
