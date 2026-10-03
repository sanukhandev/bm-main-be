<?php

namespace App\Services\Zaakiy;

final readonly class IntentFrame
{
    public function __construct(
        public string $intent,
        public array $modules,
        public string $operation,
        public array $entities = [],
        public array $metrics = [],
        public array $filters = [],
        public ?array $timeRange = null,
        public ?array $comparisonPeriod = null,
        public string $detailLevel = 'summary',
        public ?string $searchText = null,
        public int $limit = 10,
        public ?string $sort = null,
        public string $question = '',
        public bool $comparisonRequested = false,
        public bool $explanationRequested = false,
        public bool $anomalyRequested = false,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'intent' => $this->intent,
            'modules' => $this->modules,
            'operation' => $this->operation,
            'entities' => $this->entities,
            'metrics' => $this->metrics,
            'filters' => $this->filters,
            'time_range' => $this->timeRange,
            'comparison_period' => $this->comparisonPeriod,
            'detail_level' => $this->detailLevel,
            'search_text' => $this->searchText,
            'limit' => $this->limit,
            'sort' => $this->sort,
        ], static fn ($value) => $value !== null && $value !== []);
    }

    public function withTimeRange(?array $range, ?array $filters = null): self
    {
        return new self($this->intent, $this->modules, $this->operation, $this->entities, $this->metrics, $filters ?? $this->filters, $range, null, $this->detailLevel, $this->searchText, $this->limit, $this->sort, $this->question, false, $this->explanationRequested, $this->anomalyRequested);
    }
}
