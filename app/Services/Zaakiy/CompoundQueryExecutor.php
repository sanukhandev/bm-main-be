<?php

namespace App\Services\Zaakiy;

use App\Services\Zaakiy\DTOs\ZaakiyMetricTrendPoint;
use App\Services\Zaakiy\DTOs\ZaakiyQueryPlan;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\Skills\AgreementRiskSkill;
use App\Services\Zaakiy\Skills\CollectionsHealthSkill;
use App\Services\Zaakiy\Skills\MaintenanceIntelligenceSkill;
use App\Services\Zaakiy\Skills\RenewalIntelligenceSkill;
use App\Services\Zaakiy\Skills\VacancyAnalysisSkill;
use Carbon\CarbonImmutable;

final class CompoundQueryExecutor
{
    private const MAX_SECTIONS = 3;

    private const MAX_RECORDS = 10;

    private const MAX_REFERENCES = 25;

    public function __construct(
        private readonly CollectionsHealthSkill $collections,
        private readonly AgreementRiskSkill $agreementRisk,
        private readonly RenewalIntelligenceSkill $renewals,
        private readonly VacancyAnalysisSkill $vacancy,
        private readonly MaintenanceIntelligenceSkill $maintenance,
        private readonly MetricComparisonEngine $comparisonEngine,
        private readonly TrendEngine $trendEngine,
    ) {}

    public function execute(ZaakiyQueryPlan $plan, ZaakiyExecutionContext $context): ZaakiySkillResult
    {
        if (! $plan->valid) {
            return new ZaakiySkillResult('compound_query', 'Compound query', warnings: $plan->warnings, meta: ['result_type' => 'compound_query', 'read_only' => true]);
        }

        $sections = [];
        $results = [];
        $warnings = [];
        $sources = [];
        $navigation = [];
        $comparisons = [];
        $trends = [];
        foreach (array_slice($plan->capabilities, 0, self::MAX_SECTIONS) as $capability) {
            $result = $this->executeCapability($capability, $context, $plan, $plan->timeRange, $this->snapshotAsOf($capability, $plan->timeRange));
            $sectionComparisons = [];
            $sectionTrends = [];
            $operation = $this->childOperation($capability, $plan);
            if ($operation === 'compare') {
                [$sectionComparisons, $compareWarnings] = $this->compareSection($capability, $context, $plan, $result);
                $warnings = [...$warnings, ...$compareWarnings];
                $comparisons = [...$comparisons, ...$sectionComparisons];
            } elseif ($operation === 'trend') {
                [$sectionTrends, $trendWarnings] = $this->trendSection($capability, $context, $plan);
                $warnings = [...$warnings, ...$trendWarnings];
                $trends = [...$trends, ...$sectionTrends];
            }
            $results[$capability] = $result;
            $status = ($result->meta['financial_included'] ?? true) === false ? 'restricted' : ($result->warnings !== [] && $result->records === [] && $result->summaryMetrics === [] ? 'unsupported' : 'available');
            $sections[] = ['capability' => $capability, 'domain' => $this->domain($capability), 'label' => $result->subject, 'status' => $status, 'summary_metrics' => $result->summaryMetrics, 'records' => array_slice($result->records, 0, self::MAX_RECORDS), 'comparisons' => $sectionComparisons, 'trends' => $sectionTrends, 'warnings' => $result->warnings, 'navigation' => $result->navigation, 'meta' => ['bounded' => true]];
            $warnings = [...$warnings, ...array_map(fn (array $warning): array => $warning + ['section' => $capability], $result->warnings)];
            $sources = [...$sources, ...$result->sources];
            $navigation = [...$navigation, ...$result->navigation];
        }

        $correlated = [];
        if ($plan->mergeStrategy === 'intersection_by_reference') {
            $correlated = $this->intersection($results, $plan->correlation);
        } elseif ($plan->mergeStrategy === 'enrich_by_reference') {
            $warnings[] = ['code' => 'CORRELATION_UNSUPPORTED', 'message' => 'The selected capabilities do not expose a safe bounded enrichment filter.', 'section' => 'compound'];
        }
        if ($correlated !== []) {
            $sections[] = ['capability' => 'compound', 'domain' => 'correlation', 'label' => 'Correlated results', 'status' => 'available', 'summary_metrics' => ['correlated_count' => count($correlated)], 'records' => $correlated, 'warnings' => [], 'navigation' => [], 'meta' => ['correlation_type' => $plan->correlation, 'merge_strategy' => $plan->mergeStrategy]];
        }

        $summary = [];
        foreach ($results as $capability => $result) {
            foreach ($result->summaryMetrics as $metric => $value) {
                if (is_scalar($value)) {
                    $summary[$capability.'.'.$metric] = $value;
                }
            }
        }
        if ($plan->mergeStrategy === 'intersection_by_reference') {
            $summary['correlated_count'] = count($correlated);
        }

        return new ZaakiySkillResult(
            intent: 'compound_query',
            subject: 'Compound query',
            summaryMetrics: $summary,
            breakdowns: ['compound_sections' => array_slice($sections, 0, self::MAX_SECTIONS + 1)],
            comparisons: $comparisons,
            warnings: $this->uniqueWarnings($warnings),
            sources: $this->uniqueSources($sources),
            navigation: $this->uniqueNavigation($navigation),
            suggestedFollowups: ['Show only the first section.', 'Tell me about the first property.', 'Show the related agreements.'],
            timeRange: $plan->timeRange,
            meta: ['result_type' => 'compound_query', 'branch_scoped' => true, 'read_only' => true, 'merge_strategy' => $plan->mergeStrategy, 'correlation' => $plan->correlation, 'section_count' => count($sections)],
            trends: $trends,
        );
    }

    private function executeCapability(string $capability, ZaakiyExecutionContext $context, ZaakiyQueryPlan $plan, ?array $range = null, ?string $asOf = null): ZaakiySkillResult
    {
        $intent = match ($capability) {
            'collections_health' => preg_match('/overdue|outstanding|owe/i', $context->intent->question) === 1 ? 'overdue_receivables' : 'collections_summary',
            'vacancy_analysis' => preg_match('/occupancy rate|occupancy/i', $context->intent->question) === 1 ? 'occupancy_summary' : 'vacancy_analysis',
            'maintenance_intelligence' => preg_match('/completed/i', $context->intent->question) === 1 ? 'maintenance_completion' : 'maintenance_intelligence',
            'renewal_intelligence' => 'renewal_intelligence',
            'agreement_risk' => preg_match('/expir/i', $context->intent->question) === 1 ? 'agreement_expiry' : 'agreement_risk',
            default => 'general',
        };
        $filters = [];
        foreach ($plan->steps as $step) {
            if ($step->capability === $capability) {
                $filters = $step->input['filters'] ?? [];
                break;
            }
        }
        if ($asOf !== null) {
            $filters['as_of'] = $asOf;
        }
        $frame = new IntentFrame($intent, [$capability], 'search', filters: $filters, timeRange: $range ?? $plan->timeRange, question: $context->intent->question);
        $child = new ZaakiyExecutionContext($context->user, $context->branch, $frame, $context->requestAt, $context->conversation);

        return match ($capability) {
            'collections_health' => $this->collections->execute($child),
            'vacancy_analysis' => $this->vacancy->execute($child),
            'maintenance_intelligence' => $this->maintenance->execute($child),
            'renewal_intelligence' => $this->renewals->execute($child),
            'agreement_risk' => $this->agreementRisk->execute($child),
            default => new ZaakiySkillResult($intent, 'Unsupported compound section', warnings: [['code' => 'CAPABILITY_UNSUPPORTED', 'message' => 'The compound section is not registered.']]),
        };
    }

    private function childOperation(string $capability, ZaakiyQueryPlan $plan): string
    {
        foreach ($plan->steps as $step) {
            if ($step->capability === $capability) {
                return $step->meta['operation'] ?? 'read';
            }
        }

        return 'read';
    }

    /** @return array{0: array<int, array>, 1: array<int, array>} */
    private function compareSection(string $capability, ZaakiyExecutionContext $context, ZaakiyQueryPlan $plan, ZaakiySkillResult $current): array
    {
        if ($plan->timeRange === null || $plan->comparisonRange === null) {
            return [[], [['code' => 'TIME_RANGE_REQUIRED', 'section' => $capability]]];
        }
        $comparison = $this->executeCapability($capability, $context, $plan, $plan->comparisonRange, $this->snapshotAsOf($capability, $plan->comparisonRange));
        $definition = $this->metricDefinition($capability, $plan->steps);
        if ($definition === null || ! array_key_exists($definition['source'], $current->summaryMetrics) || ! array_key_exists($definition['source'], $comparison->summaryMetrics)) {
            return [[], [['code' => 'COMPOUND_METRIC_UNAVAILABLE', 'section' => $capability]]];
        }

        return [[$this->comparisonEngine->compare(
            $definition['metric'],
            $definition['label'],
            $this->numeric($current->summaryMetrics[$definition['source']]),
            $this->numeric($comparison->summaryMetrics[$definition['source']]),
            $definition['unit'],
            $plan->timeRange,
            $plan->comparisonRange,
        )->toArray()], []];
    }

    /** @return array{0: array<int, array>, 1: array<int, array>} */
    private function trendSection(string $capability, ZaakiyExecutionContext $context, ZaakiyQueryPlan $plan): array
    {
        if ($plan->timeRange === null) {
            return [[], [['code' => 'TIME_RANGE_REQUIRED', 'section' => $capability]]];
        }
        $definition = $this->metricDefinition($capability, $plan->steps);
        if ($definition === null) {
            return [[], [['code' => 'TREND_METRIC_UNSUPPORTED', 'section' => $capability]]];
        }
        $periodPlan = $this->trendEngine->periods($plan->timeRange, $plan->granularity ?? 'auto');
        if ($periodPlan['warnings'] !== []) {
            return [[], array_map(fn (array $warning): array => $warning + ['section' => $capability], $periodPlan['warnings'])];
        }
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $points = [];
        foreach ($periodPlan['periods'] as $period) {
            $snapshot = in_array($definition['time'], ['snapshot'], true);
            $asOf = $snapshot ? min($period['to'], $today) : null;
            $periodTo = $definition['future'] ? $period['to'] : min($period['to'], $today);
            $pointResult = $this->executeCapability($capability, $context, $plan, ['from' => $period['from'], 'to' => $periodTo], $asOf);
            $value = array_key_exists($definition['source'], $pointResult->summaryMetrics) && is_numeric($pointResult->summaryMetrics[$definition['source']])
                ? $this->numeric($pointResult->summaryMetrics[$definition['source']])
                : null;
            $points[] = new ZaakiyMetricTrendPoint($period['from'], $period['to'], $period['label'], $value, $asOf, ['partial_period' => $period['to'] >= $today && $period['from'] < $today]);
        }
        $trend = $this->trendEngine->build($definition['metric'], $definition['label'], $definition['unit'], $periodPlan['granularity'], $plan->timeRange, $points);

        return [[$trend->toArray()], []];
    }

    private function metricDefinition(string $capability, array $steps): ?array
    {
        $metric = null;
        foreach ($steps as $step) {
            if ($step->capability === $capability) {
                $metric = $step->metric;
                break;
            }
        }

        return match ($metric) {
            'collections.collected_amount' => ['metric' => $metric, 'source' => 'collected_amount', 'label' => 'Collected amount', 'unit' => 'AED', 'time' => 'period', 'future' => false],
            'collections.payment_count' => ['metric' => $metric, 'source' => 'payment_count', 'label' => 'Payment count', 'unit' => 'count', 'time' => 'period', 'future' => false],
            'properties.vacant_count' => ['metric' => $metric, 'source' => 'vacant_property_count', 'label' => 'Vacant properties', 'unit' => 'count', 'time' => 'snapshot', 'future' => false],
            'properties.occupancy_rate' => ['metric' => $metric, 'source' => 'occupancy_rate', 'label' => 'Occupancy rate', 'unit' => 'percent', 'time' => 'snapshot', 'future' => false],
            'maintenance.completed_work_order_count' => ['metric' => $metric, 'source' => 'completed_work_order_count', 'label' => 'Completed work orders', 'unit' => 'count', 'time' => 'period', 'future' => false],
            'agreements.renewal_candidate_count' => ['metric' => $metric, 'source' => 'renewal_candidate_count', 'label' => 'Renewal candidates', 'unit' => 'count', 'time' => 'period', 'future' => true],
            'agreements.expiring_count' => ['metric' => $metric, 'source' => 'expiring_soon_count', 'label' => 'Expiring agreements', 'unit' => 'count', 'time' => 'period', 'future' => true],
            default => null,
        };
    }

    private function snapshotAsOf(string $capability, ?array $range): ?string
    {
        if ($range === null || $capability !== 'vacancy_analysis') {
            return null;
        }

        return $range['to'];
    }

    private function numeric(mixed $value): int|float
    {
        return is_numeric($value) ? (str_contains((string) $value, '.') ? (float) $value : (int) $value) : 0;
    }

    private function intersection(array $results, ?string $type): array
    {
        if ($type === null || count($results) < 2) {
            return [];
        }
        $sets = [];
        foreach ($results as $capability => $result) {
            $refs = [];
            foreach ($result->records as $record) {
                foreach ($this->recordReferences($record, $type) as $key => $reference) {
                    $refs[$key] = $reference;
                }
            }
            $sets[$capability] = $refs;
        }
        $common = array_shift($sets) ?? [];
        foreach ($sets as $set) {
            $common = array_intersect_key($common, $set);
        }

        return array_slice(array_values(array_map(fn (array $reference): array => ['type' => $type, 'id' => $reference['id'], 'label' => $reference['label'], 'references' => [$reference]], $common)), 0, self::MAX_REFERENCES);
    }

    private function recordReferences(array $record, string $type): array
    {
        $references = [];
        $add = function (string $referenceType, mixed $id, mixed $label) use (&$references, $type): void {
            if ($referenceType === $type && is_numeric($id)) {
                $references[$referenceType.':'.$id] = ['type' => $referenceType, 'id' => (int) $id, 'label' => (string) $label];
            }
        };
        if (($record['type'] ?? null) === $type) {
            $add($type, $record['id'] ?? null, $record['label'] ?? $record['property_code'] ?? $record['agreement_no'] ?? $record['work_order_no'] ?? '');
        }
        if (isset($record[$type]['id'])) {
            $add($type, $record[$type]['id'], $record[$type]['label'] ?? $record[$type]['display_name'] ?? $record[$type]['customer_code'] ?? '');
        }
        if ($type === 'tenant' && isset($record['party']['id']) && ($record['type'] ?? '') === 'tenant_agreement') {
            $add('tenant', $record['party']['id'], $record['party']['display_name'] ?? $record['party']['customer_code'] ?? '');
        }
        if ($type === 'owner' && isset($record['party']['id']) && ($record['type'] ?? '') === 'owner_agreement') {
            $add('owner', $record['party']['id'], $record['party']['display_name'] ?? $record['party']['customer_code'] ?? '');
        }

        return $references;
    }

    private function domain(string $capability): string
    {
        return match ($capability) {
            'collections_health' => 'accounts',
            'vacancy_analysis' => 'properties',
            'maintenance_intelligence' => 'maintenance',
            'renewal_intelligence', 'agreement_risk' => 'agreements',
            default => 'unknown',
        };
    }

    private function uniqueWarnings(array $warnings): array
    {
        $unique = [];
        foreach ($warnings as $warning) {
            $key = ($warning['code'] ?? 'warning').':'.($warning['section'] ?? 'global');
            $unique[$key] = $warning;
        }

        return array_values($unique);
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
            $unique[($item['route'] ?? '').':'.($item['label'] ?? '')] = $item;
        }

        return array_values(array_slice($unique, 0, 15));
    }
}
