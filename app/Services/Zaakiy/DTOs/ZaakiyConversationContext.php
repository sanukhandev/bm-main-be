<?php

namespace App\Services\Zaakiy\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class ZaakiyConversationContext implements Arrayable, JsonSerializable
{
    public function __construct(
        public ?string $intent = null,
        public ?string $domain = null,
        public ?string $subject = null,
        public ?string $metric = null,
        public array $entities = [],
        public ?array $timeRange = null,
        public ?array $comparisonRange = null,
        public array $filters = [],
        public array $sort = [],
        public array $resultReferences = [],
        public array $branchContext = [],
        public array $meta = [],
    ) {}

    public static function fromArray(?array $value): self
    {
        $value ??= [];

        return new self(
            intent: self::stringValue($value['intent'] ?? null, 100),
            domain: self::stringValue($value['domain'] ?? null, 50),
            subject: self::stringValue($value['subject'] ?? null, 255),
            metric: self::stringValue($value['metric'] ?? null, 100),
            entities: self::boundedList($value['entities'] ?? [], 10),
            timeRange: self::range($value['time_range'] ?? null),
            comparisonRange: self::range($value['comparison_range'] ?? null),
            filters: self::boundedMap($value['filters'] ?? [], 10),
            sort: self::boundedMap($value['sort'] ?? [], 3),
            resultReferences: self::boundedList($value['result_references'] ?? [], 25),
            branchContext: self::boundedMap($value['branch_context'] ?? [], 3),
            meta: [],
        );
    }

    public function with(array $changes): self
    {
        return new self(
            intent: $changes['intent'] ?? $this->intent,
            domain: $changes['domain'] ?? $this->domain,
            subject: $changes['subject'] ?? $this->subject,
            metric: $changes['metric'] ?? $this->metric,
            entities: $changes['entities'] ?? $this->entities,
            timeRange: $changes['timeRange'] ?? $this->timeRange,
            comparisonRange: $changes['comparisonRange'] ?? $this->comparisonRange,
            filters: $changes['filters'] ?? $this->filters,
            sort: $changes['sort'] ?? $this->sort,
            resultReferences: $changes['resultReferences'] ?? $this->resultReferences,
            branchContext: $changes['branchContext'] ?? $this->branchContext,
            meta: $changes['meta'] ?? $this->meta,
        );
    }

    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'domain' => $this->domain,
            'subject' => $this->subject,
            'metric' => $this->metric,
            'entities' => $this->entities,
            'time_range' => $this->timeRange,
            'comparison_range' => $this->comparisonRange,
            'filters' => $this->filters,
            'sort' => $this->sort,
            'result_references' => $this->resultReferences,
            'branch_context' => $this->branchContext,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function stringValue(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    private static function boundedList(mixed $value, int $limit): array
    {
        return is_array($value) ? array_values(array_slice(array_filter($value, 'is_array'), 0, $limit)) : [];
    }

    private static function boundedMap(mixed $value, int $limit): array
    {
        return is_array($value) ? array_slice($value, 0, $limit, true) : [];
    }

    private static function range(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $from = is_string($value['from'] ?? null) ? $value['from'] : null;
        $to = is_string($value['to'] ?? null) ? $value['to'] : null;

        return $from && $to ? ['from' => $from, 'to' => $to, 'label' => self::stringValue($value['label'] ?? null, 100)] : null;
    }
}
