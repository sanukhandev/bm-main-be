<?php

namespace App\Services\Zaakiy;

final class ZaakiyPresentationBuilder
{
    private const MAX_RESULTS = 5;

    private const MAX_RECORDS = 10;

    private const MAX_ITEMS = 25;

    public function __construct(private readonly SensitiveDataFilter $filter) {}

    /** @return array<int, array{event: string, data: array}> */
    public function build(array $context): array
    {
        $events = [];
        foreach (array_slice($context['evidence'] ?? [], 0, self::MAX_RESULTS) as $result) {
            if (! is_array($result)) {
                continue;
            }
            $this->add($events, 'summary', ['metrics' => $this->summaryMetrics($result['summary_metrics'] ?? [])]);
            $this->add($events, 'records', ['records' => $this->records($result['records'] ?? [])]);
            $this->add($events, 'comparison', ['comparisons' => array_slice($this->safeList($result['comparisons'] ?? []), 0, self::MAX_ITEMS)]);
            $this->add($events, 'trend', ['trends' => array_slice($this->safeList($result['trends'] ?? []), 0, 3)]);
            $this->add($events, 'explanation', ['explanations' => array_slice($this->safeList($result['explanations'] ?? []), 0, 3)]);
            $this->add($events, 'anomalies', ['anomalies' => array_slice($this->safeList($result['anomalies'] ?? []), 0, self::MAX_ITEMS)]);
            $this->add($events, 'sections', ['sections' => $this->sections($result['breakdowns'] ?? [])]);
            $this->add($events, 'warnings', ['warnings' => array_slice($this->safeList($result['warnings'] ?? []), 0, self::MAX_ITEMS)]);
            $this->add($events, 'suggestions', ['suggestions' => array_slice(array_values(array_filter($result['suggested_followups'] ?? [], 'is_string')), 0, 5)]);
        }

        return $this->filter->clean($events);
    }

    private function add(array &$events, string $event, array $data): void
    {
        if ($data === [] || $this->emptyPayload($data)) {
            return;
        }
        $events[] = ['event' => $event, 'data' => $data];
    }

    private function emptyPayload(array $data): bool
    {
        foreach ($data as $value) {
            if ($value !== []) {
                return false;
            }
        }

        return true;
    }

    private function summaryMetrics(array $metrics): array
    {
        $cards = [];
        foreach ($metrics as $key => $value) {
            if (! is_scalar($value) || ! is_string($key)) {
                continue;
            }
            $metric = str_contains($key, '.') ? $key : null;
            $cards[] = [
                'metric' => $metric ?? $key,
                'label' => $this->label($metric ?? $key),
                'value' => $value,
                'unit' => $this->unit($metric ?? $key),
                'format_hint' => $this->formatHint($metric ?? $key),
            ];
        }

        return array_slice($cards, 0, self::MAX_ITEMS);
    }

    private function records(array $records): array
    {
        $projected = [];
        foreach (array_slice($records, 0, self::MAX_RECORDS) as $record) {
            if (! is_array($record)) {
                continue;
            }
            $projected[] = array_filter([
                'type' => $record['type'] ?? null,
                'id' => is_numeric($record['id'] ?? null) ? (int) $record['id'] : null,
                'label' => $record['label'] ?? $record['property_code'] ?? $record['agreement_no'] ?? $record['work_order_no'] ?? null,
                'status' => $record['status'] ?? null,
                'fields' => $this->fields($record),
            ], static fn ($value): bool => $value !== null && $value !== []);
        }

        return $projected;
    }

    private function fields(array $record): array
    {
        $allowed = ['property_code', 'property_type', 'agreement_no', 'work_order_no', 'priority', 'age_days', 'vacancy_days', 'amount', 'balance', 'days_remaining'];

        return array_intersect_key($record, array_flip($allowed));
    }

    private function sections(array $breakdowns): array
    {
        $sections = $breakdowns['compound_sections'] ?? $breakdowns['briefing_sections'] ?? [];
        if (! is_array($sections)) {
            return [];
        }

        return array_slice(array_map(function ($section): array {
            if (! is_array($section)) {
                return [];
            }

            return array_filter([
                'code' => $section['code'] ?? $section['capability'] ?? null,
                'capability' => $section['capability'] ?? null,
                'domain' => $section['domain'] ?? null,
                'title' => $section['title'] ?? $section['label'] ?? null,
                'status' => $section['status'] ?? null,
                'summary_metrics' => $this->summaryMetrics($section['summary_metrics'] ?? []),
                'records' => $this->records($section['records'] ?? []),
                'comparisons' => array_slice($this->safeList($section['comparisons'] ?? []), 0, 3),
                'trends' => array_slice($this->safeList($section['trends'] ?? []), 0, 2),
                'warnings' => array_slice($this->safeList($section['warnings'] ?? []), 0, 5),
                'navigation' => array_slice($this->safeList($section['navigation'] ?? []), 0, 3),
            ], static fn ($value): bool => $value !== null && $value !== []);
        }, $sections), 0, self::MAX_RESULTS);
    }

    private function safeList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private function label(string $metric): string
    {
        return match ($metric) {
            'collections.collected_amount', 'collected_amount' => 'Collected amount',
            'collections.payment_count', 'payment_count' => 'Payments',
            'collections.overdue_amount', 'overdue_amount' => 'Overdue amount',
            'properties.occupancy_rate', 'occupancy_rate' => 'Occupancy rate',
            'properties.vacant_count', 'vacant_property_count' => 'Vacant properties',
            'maintenance.open_work_order_count' => 'Open work orders',
            'maintenance.completed_work_order_count', 'completed_work_order_count' => 'Completed work orders',
            default => ucwords(str_replace(['.', '_'], [' · ', ' '], $metric)),
        };
    }

    private function unit(string $metric): string
    {
        return match (true) {
            str_contains($metric, 'amount') => 'AED',
            str_contains($metric, 'rate') => 'percent',
            str_contains($metric, 'days') => 'days',
            default => 'count',
        };
    }

    private function formatHint(string $metric): string
    {
        return $this->unit($metric) === 'AED' ? 'money' : $this->unit($metric);
    }
}
