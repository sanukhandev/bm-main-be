<?php

namespace App\Services\Zaakiy\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class ZaakiyCapability implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $id,
        public string $skill,
        public string $domain,
        public string $description,
        public array $intents = [],
        public array $metrics = [],
        public array $filters = [],
        public array $time = [],
        public array $requiredPermissions = [],
        public array $optionalFeaturePermissions = [],
        public array $entities = [],
        public array $features = [],
        public array $resultReferences = [],
        public array $drilldowns = [],
        public array $warnings = [],
        public bool $composite = false,
        public array $dependencies = [],
        public bool $readOnly = true,
    ) {}

    public function supportsMetric(string $metric): bool
    {
        return isset($this->metrics[$metric]);
    }

    public function supportsFilter(string $filter): bool
    {
        return isset($this->filters[$filter]);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'skill' => $this->skill,
            'domain' => $this->domain,
            'description' => $this->description,
            'intents' => $this->intents,
            'metrics' => array_map(fn (ZaakiyMetricCapability $metric): array => $metric->toArray(), $this->metrics),
            'filters' => array_map(fn (ZaakiyFilterCapability $filter): array => $filter->toArray(), $this->filters),
            'time' => $this->time,
            'required_permissions' => $this->requiredPermissions,
            'optional_feature_permissions' => $this->optionalFeaturePermissions,
            'entities' => $this->entities,
            'features' => $this->features,
            'result_references' => $this->resultReferences,
            'drilldowns' => $this->drilldowns,
            'warnings' => $this->warnings,
            'composite' => $this->composite,
            'dependencies' => $this->dependencies,
            'read_only' => $this->readOnly,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
