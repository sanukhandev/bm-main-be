<?php

namespace App\Services\Zaakiy\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class ZaakiyQueryPlan implements Arrayable, JsonSerializable
{
    public function __construct(
        public bool $valid,
        public ?string $capability = null,
        public ?string $domain = null,
        public string $operation = 'read',
        public ?string $metric = null,
        public array $filters = [],
        public array $entity = [],
        public ?array $timeRange = null,
        public ?array $comparisonRange = null,
        public ?string $granularity = null,
        public array $steps = [],
        public array $warnings = [],
        public array $meta = [],
        public array $capabilities = [],
        public ?string $mergeStrategy = null,
        public ?string $correlation = null,
    ) {}

    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'capability' => $this->capability,
            'domain' => $this->domain,
            'operation' => $this->operation,
            'metric' => $this->metric,
            'filters' => $this->filters,
            'entity' => $this->entity,
            'time_range' => $this->timeRange,
            'comparison_range' => $this->comparisonRange,
            'granularity' => $this->granularity,
            'steps' => array_map(fn (ZaakiyQueryPlanStep $step): array => $step->toArray(), $this->steps),
            'warnings' => $this->warnings,
            'meta' => $this->meta,
            'capabilities' => $this->capabilities,
            'merge_strategy' => $this->mergeStrategy,
            'correlation' => $this->correlation,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
