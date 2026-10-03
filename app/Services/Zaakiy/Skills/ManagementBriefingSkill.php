<?php

namespace App\Services\Zaakiy\Skills;

use App\Services\Zaakiy\AnomalyDetectionEngine;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\MetricComparisonEngine;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Services\Zaakiy\ZaakiyReadSkill;
use Carbon\CarbonImmutable;

final class ManagementBriefingSkill implements ZaakiyReadSkill
{
    public function __construct(
        private readonly CollectionsHealthSkill $collections,
        private readonly AgreementRiskSkill $agreementRisk,
        private readonly RenewalIntelligenceSkill $renewals,
        private readonly VacancyAnalysisSkill $vacancy,
        private readonly MaintenanceIntelligenceSkill $maintenance,
        private readonly AnomalyDetectionEngine $anomalies,
        private readonly MetricComparisonEngine $comparisonEngine,
    ) {}

    public function supports(IntentFrame $intent): bool
    {
        return $intent->intent === 'management_briefing' || in_array('management_briefing', $intent->modules, true);
    }

    public function execute(ZaakiyExecutionContext $context): ZaakiySkillResult
    {
        $period = $context->intent->timeRange ?? $this->today();
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $sections = [];
        $sources = [];
        $navigation = [];
        $warnings = [];
        $summary = [];
        $attention = [];
        $comparisons = [];

        $collections = $this->run($this->collections, $context, 'collections_summary', $period);
        if (($collections->meta['financial_included'] ?? true) === false) {
            $sections[] = $this->section('collections', 'Collections', 'restricted', [], [], $collections->warnings);
        } else {
            $sections[] = $this->section('collections', 'Collections', 'available', $collections->summaryMetrics, [], []);
            foreach (['collected_amount', 'outstanding_total', 'overdue_total'] as $metric) {
                if (array_key_exists($metric, $collections->summaryMetrics)) {
                    $summary[$metric] = $collections->summaryMetrics[$metric];
                }
            }
            if ((int) ($collections->summaryMetrics['bounced_cheque_count'] ?? 0) > 0) {
                $attention[] = $this->anomalies->condition('BOUNCED_CHEQUE_PRESENT', 'collections.bounced_cheque_count', 'Bounced cheques', 'attention', (int) $collections->summaryMetrics['bounced_cheque_count'], ['minimum' => 1], 'bounced cheque count is at least one');
            }
            $sources = [...$sources, ...$collections->sources];
            $navigation = [...$navigation, ...$collections->navigation];
        }

        $occupancy = $this->run($this->vacancy, $context, 'occupancy_summary', $period, ['as_of' => $today]);
        $sections[] = $this->section('occupancy', 'Occupancy', 'available', $occupancy->summaryMetrics, [], $occupancy->warnings);
        foreach (['eligible_property_count', 'occupied_property_count', 'vacant_property_count', 'occupancy_rate'] as $metric) {
            if (array_key_exists($metric, $occupancy->summaryMetrics)) {
                $summary[$metric] = $occupancy->summaryMetrics[$metric];
            }
        }
        foreach ($occupancy->records as $record) {
            if (($record['vacancy_days'] ?? null) !== null && (int) $record['vacancy_days'] >= 90) {
                $attention[] = $this->anomalies->condition('LONG_VACANCY_90_PLUS', 'properties.vacant_count', 'Long vacancy', 'attention', (int) $record['vacancy_days'], ['minimum_days' => 90], 'vacancy_days >= 90', [['type' => 'property', 'id' => $record['id'], 'label' => $record['property_code']]]);
            }
        }
        $sources = [...$sources, ...$occupancy->sources];
        $navigation = [...$navigation, ...$occupancy->navigation];

        $risk = $this->run($this->agreementRisk, $context, 'agreement_risk', ['from' => $today, 'to' => CarbonImmutable::parse($today)->addDays(30)->toDateString()]);
        $renewals = $this->run($this->renewals, $context, 'renewal_intelligence', ['from' => $today, 'to' => CarbonImmutable::parse($today)->addDays(60)->toDateString()]);
        $sections[] = $this->section('agreements', 'Agreements', 'available', $risk->summaryMetrics, array_slice($risk->records, 0, 3), $risk->warnings);
        $sections[] = $this->section('renewals', 'Renewals', 'available', $renewals->summaryMetrics, array_slice($renewals->records, 0, 3), $renewals->warnings);
        foreach ($risk->records as $record) {
            foreach ((array) ($record['signals'] ?? []) as $signal) {
                $mapping = match ($signal) {
                    'OWNER_COVERAGE_BEFORE_TENANT_END' => ['OWNER_COVERAGE_MISMATCH', 'critical', 'owner coverage ends before tenant agreement'],
                    'EXPIRED_ACTIVE_STATUS' => ['AGREEMENT_EXPIRED_ACTIVE', 'critical', 'agreement is expired while still active'],
                    default => null,
                };
                if ($mapping) {
                    $attention[] = $this->anomalies->condition($mapping[0], 'agreements.attention_count', 'Agreement attention', $mapping[1], 1, ['minimum' => 1], $mapping[2], [['type' => 'agreement', 'id' => $record['id'], 'label' => $record['agreement_no']]]);
                }
            }
        }
        $summary['renewal_candidate_count'] = $renewals->summaryMetrics['renewal_candidate_count'] ?? 0;
        $summary['expiring_soon_count'] = $risk->summaryMetrics['expiring_soon_count'] ?? 0;
        $sources = [...$sources, ...$risk->sources, ...$renewals->sources];
        $navigation = [...$navigation, ...$risk->navigation, ...$renewals->navigation];

        $maintenance = $this->run($this->maintenance, $context, 'maintenance_intelligence', $period);
        $maintenanceCompleted = $this->run($this->maintenance, $context, 'maintenance_completion', $period);
        $maintenanceMetrics = $maintenance->summaryMetrics + $maintenanceCompleted->summaryMetrics;
        $sections[] = $this->section('maintenance', 'Maintenance', 'available', $maintenanceMetrics, array_slice($maintenance->records, 0, 3), [...$maintenance->warnings, ...$maintenanceCompleted->warnings]);
        foreach (['open_work_order_count', 'high_priority_open_count', 'oldest_open_age_days', 'properties_with_open_work_orders'] as $metric) {
            if (array_key_exists($metric, $maintenanceMetrics)) {
                $summary[$metric] = $maintenanceMetrics[$metric];
            }
        }
        foreach ($maintenance->records as $record) {
            if (($record['age_days'] ?? 0) >= 60) {
                $attention[] = $this->anomalies->condition('OPEN_WORK_ORDER_60_PLUS', 'maintenance.open_work_order_count', 'Old open work order', 'attention', (int) $record['age_days'], ['minimum_days' => 60], 'age_days >= 60', [['type' => 'work_order', 'id' => $record['id'], 'label' => $record['work_order_no']]]);
            }
            if (($record['priority'] ?? null) === 'urgent') {
                $attention[] = $this->anomalies->condition('URGENT_OPEN_WORK_ORDER_PRESENT', 'maintenance.open_work_order_count', 'Urgent open work order', 'attention', 1, ['minimum' => 1], 'priority is urgent', [['type' => 'work_order', 'id' => $record['id'], 'label' => $record['work_order_no']]]);
            }
        }
        $sources = [...$sources, ...$maintenance->sources];
        $navigation = [...$navigation, ...$maintenance->navigation];

        if ($context->intent->comparisonRequested && $context->intent->comparisonPeriod !== null) {
            $comparisons = $this->comparisons($context, $period, $context->intent->comparisonPeriod, $collections, $occupancy, $maintenanceCompleted, $renewals);
        }

        $attention = $this->uniqueAnomalies($this->anomalies->sortAndBound($attention), 5);
        $sections = [['code' => 'attention', 'title' => 'Attention', 'status' => $attention === [] ? 'empty' : 'available', 'summary_metrics' => ['count' => count($attention)], 'records' => array_map(fn ($item): array => $item->toArray(), $attention), 'warnings' => [], 'meta' => ['bounded' => true]], ...$sections];
        $summary['attention_item_count'] = count($attention);
        $summary['briefing_period'] = $period;

        return new ZaakiySkillResult(
            intent: 'management_briefing',
            subject: 'Management briefing',
            summaryMetrics: $summary,
            records: [],
            breakdowns: ['briefing_sections' => $sections],
            comparisons: $comparisons,
            warnings: $this->uniqueWarnings([...$warnings, ...array_merge(...array_map(fn (array $section): array => $section['warnings'], $sections))]),
            sources: $this->uniqueSources($sources),
            navigation: $this->uniqueNavigation($navigation),
            suggestedFollowups: ['Show the attention items.', 'Show vacant properties.', 'Show renewals due in the next 30 days.', 'Show the oldest open maintenance.', 'Compare this briefing with last month.'],
            timeRange: $period,
            meta: ['result_type' => 'management_briefing', 'branch_scoped' => true, 'deterministic' => true, 'read_only' => true, 'provider_payload_bounded' => true, 'snapshot_as_of' => $today],
            anomalies: array_map(fn ($item): array => $item->toArray(), $attention),
        );
    }

    private function run(ZaakiyReadSkill $skill, ZaakiyExecutionContext $context, string $intent, array $range, array $filters = []): ZaakiySkillResult
    {
        $frame = new IntentFrame($intent, [$intent], 'aggregate', question: $context->intent->question, filters: $filters, timeRange: $range);

        return $skill->execute(new ZaakiyExecutionContext($context->user, $context->branch, $frame, $context->requestAt, $context->conversation));
    }

    private function comparisons(ZaakiyExecutionContext $context, array $currentRange, array $comparisonRange, ZaakiySkillResult $collections, ZaakiySkillResult $occupancy, ZaakiySkillResult $maintenance, ZaakiySkillResult $renewals): array
    {
        $items = [];
        if (($collections->meta['financial_included'] ?? true) && $context->can('accounts.view')) {
            $previous = $this->run($this->collections, $context, 'collections_summary', $comparisonRange);
            $items[] = $this->comparisonEngine->compare('collections.collected_amount', 'Collected amount', $collections->summaryMetrics['collected_amount'] ?? 0, $previous->summaryMetrics['collected_amount'] ?? 0, 'AED', $currentRange, $comparisonRange)->toArray();
        }
        $previousOccupancy = $this->run($this->vacancy, $context, 'occupancy_summary', ['from' => $comparisonRange['from'], 'to' => $comparisonRange['to']], ['as_of' => $comparisonRange['to']]);
        $items[] = $this->comparisonEngine->compare('properties.occupancy_rate', 'Occupancy rate', $occupancy->summaryMetrics['occupancy_rate'] ?? 0, $previousOccupancy->summaryMetrics['occupancy_rate'] ?? 0, 'percent', $currentRange, $comparisonRange)->toArray();
        $previousMaintenance = $this->run($this->maintenance, $context, 'maintenance_completion', $comparisonRange);
        $items[] = $this->comparisonEngine->compare('maintenance.completed_work_order_count', 'Completed work orders', $this->completed($maintenance), $this->completed($previousMaintenance), 'count', $currentRange, $comparisonRange)->toArray();
        $previousRenewals = $this->run($this->renewals, $context, 'renewal_intelligence', $comparisonRange);
        $items[] = $this->comparisonEngine->compare('agreements.renewal_candidate_count', 'Renewal candidates', $renewals->summaryMetrics['renewal_candidate_count'] ?? 0, $previousRenewals->summaryMetrics['renewal_candidate_count'] ?? 0, 'count', $currentRange, $comparisonRange)->toArray();

        return $items;
    }

    private function completed(ZaakiySkillResult $result): int
    {
        return (int) ($result->summaryMetrics['completed_work_order_count'] ?? 0);
    }

    private function section(string $code, string $title, string $status, array $metrics, array $records, array $warnings): array
    {
        return ['code' => $code, 'title' => $title, 'status' => $status, 'summary_metrics' => $metrics, 'records' => $records, 'warnings' => $warnings, 'meta' => ['bounded' => true]];
    }

    private function today(): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();

        return ['from' => $today, 'to' => $today];
    }

    private function uniqueAnomalies(array $items, int $limit): array
    {
        $unique = [];
        foreach ($items as $item) {
            $reference = $item->references[0]['id'] ?? '';
            $unique[$item->code.':'.$reference] = $item;
        }

        return array_slice(array_values($unique), 0, $limit);
    }

    private function uniqueSources(array $sources): array
    {
        $unique = [];
        foreach ($sources as $source) {
            if (isset($source['type'], $source['id'])) {
                $unique[$source['type'].':'.$source['id']] = $source;
            }
        }

        return array_values(array_slice($unique, 0, 50));
    }

    private function uniqueNavigation(array $navigation): array
    {
        $unique = [];
        foreach ($navigation as $item) {
            $unique[$item['route'].':'.json_encode($item['query'] ?? [])] = $item;
        }

        return array_values(array_slice($unique, 0, 10));
    }

    private function uniqueWarnings(array $warnings): array
    {
        $unique = [];
        foreach ($warnings as $warning) {
            $unique[$warning['code'] ?? md5(json_encode($warning))] = $warning;
        }

        return array_values($unique);
    }
}
