<?php

namespace App\Services\Zaakiy;

use App\Models\User;
use App\Services\Zaakiy\DTOs\ZaakiyMetricTrendPoint;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\Skills\AccountsSkill;
use App\Services\Zaakiy\Skills\Agreement360Skill;
use App\Services\Zaakiy\Skills\AgreementRiskSkill;
use App\Services\Zaakiy\Skills\AgreementsSkill;
use App\Services\Zaakiy\Skills\AuditSkill;
use App\Services\Zaakiy\Skills\BillingSkill;
use App\Services\Zaakiy\Skills\CollectionsHealthSkill;
use App\Services\Zaakiy\Skills\CustomersSkill;
use App\Services\Zaakiy\Skills\DashboardSkill;
use App\Services\Zaakiy\Skills\FaqSkill;
use App\Services\Zaakiy\Skills\GeneralSkill;
use App\Services\Zaakiy\Skills\IdentitySkill;
use App\Services\Zaakiy\Skills\IntelligentReportSkill;
use App\Services\Zaakiy\Skills\MaintenanceIntelligenceSkill;
use App\Services\Zaakiy\Skills\MaintenanceSkill;
use App\Services\Zaakiy\Skills\ManagementBriefingSkill;
use App\Services\Zaakiy\Skills\Owner360Skill;
use App\Services\Zaakiy\Skills\PropertiesSkill;
use App\Services\Zaakiy\Skills\Property360Skill;
use App\Services\Zaakiy\Skills\RenewalIntelligenceSkill;
use App\Services\Zaakiy\Skills\ReportsSkill;
use App\Services\Zaakiy\Skills\Tenant360Skill;
use App\Services\Zaakiy\Skills\VacancyAnalysisSkill;
use App\Support\Branch\BranchContext;
use Carbon\CarbonImmutable;

class ReadOrchestrator
{
    public function __construct(
        private readonly IntentAnalyzer $analyzer,
        private readonly ZaakiyContextBuilder $contextBuilder,
        private readonly EntityResolver $entities,
        private readonly IdentitySkill $identity,
        private readonly AccountsSkill $accounts,
        private readonly MaintenanceSkill $maintenance,
        private readonly AgreementsSkill $agreements,
        private readonly PropertiesSkill $properties,
        private readonly CustomersSkill $customers,
        private readonly DashboardSkill $dashboard,
        private readonly ReportsSkill $reports,
        private readonly BillingSkill $billing,
        private readonly AuditSkill $audit,
        private readonly GeneralSkill $general,
        private readonly IntelligentReportSkill $intelligentReport,
        private readonly FaqSkill $faq,
        private readonly ZaakiyConversationContextResolver $conversation,
        private readonly Property360Skill $property360,
        private readonly Tenant360Skill $tenant360,
        private readonly Owner360Skill $owner360,
        private readonly Agreement360Skill $agreement360,
        private readonly CollectionsHealthSkill $collectionsHealth,
        private readonly AgreementRiskSkill $agreementRisk,
        private readonly RenewalIntelligenceSkill $renewalIntelligence,
        private readonly VacancyAnalysisSkill $vacancyAnalysis,
        private readonly MaintenanceIntelligenceSkill $maintenanceIntelligence,
        private readonly ManagementBriefingSkill $managementBriefing,
        private readonly MetricComparisonEngine $comparisonEngine,
        private readonly TrendEngine $trendEngine,
        private readonly MetricExplanationEngine $explanationEngine,
        private readonly AnomalyDetectionEngine $anomalyEngine,
        private readonly QueryPlanner $queryPlanner,
        private readonly CompoundQueryExecutor $compoundQueryExecutor,
    ) {}

    public function build(string $message, array $history, User $user, BranchContext $branch, ?array $rawConversation = null): array
    {
        $intent = $this->analyzer->analyze($message, $history);
        $conversation = $this->conversation->resolve($rawConversation, $intent, $message, $branch);
        $intent = $this->conversation->applyToIntent($intent, $conversation, $message);
        $execution = new ZaakiyExecutionContext($user, $branch, $intent, now()->toIso8601String(), $conversation);
        $conversation = $this->entities->reauthorize($conversation, $execution);
        $execution = new ZaakiyExecutionContext($user, $branch, $intent, $execution->requestAt, $conversation);
        $skills = [
            'identity' => $this->identity, 'accounts' => $this->accounts, 'maintenance' => $this->maintenance,
            'agreements' => $this->agreements, 'properties' => $this->properties, 'customers' => $this->customers,
            'dashboard' => $this->dashboard, 'reports' => $this->reports, 'billing' => $this->billing,
            'audit' => $this->audit, 'general' => $this->general,
            'intelligent_report' => $this->intelligentReport,
            'faq' => $this->faq,
            'property_360' => $this->property360,
            'tenant_360' => $this->tenant360,
            'owner_360' => $this->owner360,
            'agreement_360' => $this->agreement360,
            'collections_health' => $this->collectionsHealth,
            'agreement_risk' => $this->agreementRisk,
            'renewal_intelligence' => $this->renewalIntelligence,
            'vacancy_analysis' => $this->vacancyAnalysis,
            'maintenance_intelligence' => $this->maintenanceIntelligence,
            'management_briefing' => $this->managementBriefing,
        ];
        $results = [];
        $comparisonSkill = null;
        $plan = $this->queryPlanner->plan($intent, $conversation);
        if ($plan->operation === 'compound') {
            $results[] = $this->compoundQueryExecutor->execute($plan, $execution);
        } else {
            foreach ($intent->modules as $module) {
                $skill = $skills[$module] ?? null;
                if ($skill instanceof ZaakiyReadSkill && $skill->supports($intent)) {
                    $results[] = $skill->execute($execution);
                    $comparisonSkill ??= $skill;
                } elseif ($skill instanceof ZaakiySkill && $skill->matches($message)) {
                    $results[] = (new LegacySkillAdapter($module, $skill))->execute($execution);
                }
            }
        }
        if ($results === []) {
            $results[] = (new LegacySkillAdapter('general', $this->general))->execute($execution);
        }
        if ($comparisonSkill instanceof ZaakiyReadSkill && ! $comparisonSkill instanceof ManagementBriefingSkill && $intent->comparisonRequested && $intent->comparisonPeriod !== null && $results[0] instanceof ZaakiySkillResult) {
            $results[0] = $this->withComparisons($results[0], $intent, $execution, $comparisonSkill);
        } elseif ($comparisonSkill instanceof ZaakiyReadSkill && ($intent->filters['trend'] ?? false) && $results[0] instanceof ZaakiySkillResult) {
            $results[0] = $this->withTrend($results[0], $intent, $execution, $comparisonSkill);
        }
        if ($comparisonSkill instanceof ZaakiyReadSkill && $intent->explanationRequested && $results[0] instanceof ZaakiySkillResult) {
            $results[0] = $this->withExplanation($results[0], $intent, $execution, $comparisonSkill);
        }
        if ($comparisonSkill instanceof ZaakiyReadSkill && $intent->anomalyRequested && $results[0] instanceof ZaakiySkillResult) {
            $results[0] = $this->withAnomalies($results[0], $intent);
        }
        $entityEvidence = $this->entities->resolve($message, $execution);
        if ($entityEvidence) {
            $results[] = $entityEvidence;
        }

        $conversation = $this->conversation->complete($conversation, $intent, $results, $branch);

        return $this->contextBuilder->build($intent, $execution, $results, $conversation);
    }

    private function withComparisons(ZaakiySkillResult $result, IntentFrame $intent, ZaakiyExecutionContext $execution, ZaakiyReadSkill $skill): ZaakiySkillResult
    {
        if (($result->meta['financial_included'] ?? true) === false) {
            return $result;
        }

        $primary = $intent->timeRange ?? $this->currentMonth();
        $comparison = $intent->comparisonPeriod;
        $metrics = $this->comparisonMetrics($intent, $result);
        if ($metrics === []) {
            return $this->resultWithWarning($result, 'HISTORICAL_SNAPSHOT_UNAVAILABLE', 'A reliable comparison for this snapshot metric is not available.');
        }

        $comparisonFilters = $intent->filters;
        if (in_array($intent->intent, ['vacancy_analysis', 'occupancy_summary', 'vacant_properties', 'property_availability', 'upcoming_vacancy'], true)) {
            $comparisonFilters['as_of'] = $comparison['to'];
        }
        $comparisonResult = $skill->execute(new ZaakiyExecutionContext($execution->user, $execution->branch, $intent->withTimeRange($comparison, $comparisonFilters), $execution->requestAt, $execution->conversation));
        $comparisons = [];
        foreach ($metrics as [$source, $metric, $label, $unit]) {
            if (! array_key_exists($source, $result->summaryMetrics) || ! array_key_exists($source, $comparisonResult->summaryMetrics)) {
                continue;
            }
            $comparisons[] = $this->comparisonEngine->compare($metric, $label, $this->numeric($result->summaryMetrics[$source]), $this->numeric($comparisonResult->summaryMetrics[$source]), $unit, $primary, $comparison)->toArray();
        }
        if ($comparisons === []) {
            return $this->resultWithWarning($result, 'HISTORICAL_SNAPSHOT_UNAVAILABLE', 'The requested comparison could not be calculated from available backend data.');
        }

        return new ZaakiySkillResult($result->intent, $result->subject, $result->summaryMetrics, $result->records, $result->breakdowns, $comparisons, $result->warnings, $result->sources, $result->navigation, $result->suggestedFollowups, $primary, $result->meta + ['comparison_requested' => true, 'comparison_count' => count($comparisons)]);
    }

    private function withTrend(ZaakiySkillResult $result, IntentFrame $intent, ZaakiyExecutionContext $execution, ZaakiyReadSkill $skill): ZaakiySkillResult
    {
        $metric = $this->trendMetric($intent);
        if ($metric === null) {
            return $this->resultWithWarning($result, 'TREND_METRIC_UNSUPPORTED', 'This metric does not have a supported trend provider.');
        }
        if ($intent->intent === 'maintenance_backlog' || in_array($intent->intent, ['outstanding_receivables', 'overdue_receivables'], true)) {
            return $this->resultWithWarning($result, 'HISTORICAL_SNAPSHOT_UNAVAILABLE', 'A reliable historical snapshot is not available for this metric.');
        }
        $range = $intent->timeRange ?? $this->currentMonth();
        $plan = $this->trendEngine->periods($range, $intent->filters['trend_granularity'] ?? 'auto');
        if ($plan['warnings'] !== []) {
            return $this->resultWithWarning($result, $plan['warnings'][0]['code'], 'The requested trend range is too large or unsupported.');
        }
        [$source, $label, $unit] = $metric;
        $points = [];
        $warnings = $result->warnings;
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        foreach ($plan['periods'] as $period) {
            $filters = $intent->filters + ['trend' => true];
            $asOf = min($period['to'], $today);
            if (in_array($intent->intent, ['vacancy_analysis', 'occupancy_summary', 'vacant_properties', 'property_availability', 'upcoming_vacancy'], true)) {
                $filters['as_of'] = $asOf;
            }
            $periodTo = $period['to'];
            if (! in_array($intent->intent, ['renewal_intelligence', 'renewal_candidates', 'renewals_due', 'renewal_attention'], true)) {
                $periodTo = min($periodTo, $today);
            }
            $pointResult = $skill->execute(new ZaakiyExecutionContext($execution->user, $execution->branch, $intent->withTimeRange(['from' => $period['from'], 'to' => $periodTo], $filters), $execution->requestAt, $execution->conversation));
            $value = array_key_exists($source, $pointResult->summaryMetrics) && is_numeric($pointResult->summaryMetrics[$source]) ? $this->numeric($pointResult->summaryMetrics[$source]) : null;
            if ($value === null) {
                $warnings[] = ['code' => 'TREND_POINT_UNAVAILABLE', 'period' => $period['from']];
            }
            $points[] = new ZaakiyMetricTrendPoint($period['from'], $period['to'], $period['label'], $value, in_array($intent->intent, ['vacancy_analysis', 'occupancy_summary', 'vacant_properties', 'property_availability', 'upcoming_vacancy'], true) ? $asOf : null, ['partial_period' => $period['to'] >= $today && $period['from'] < $today]);
        }
        $trend = $this->trendEngine->build($metric[3], $label, $unit, $plan['granularity'], $range, $points);

        return new ZaakiySkillResult($result->intent, $result->subject, $result->summaryMetrics, $result->records, $result->breakdowns, $result->comparisons, $warnings, $result->sources, $result->navigation, $result->suggestedFollowups, $range, $result->meta + ['trend_requested' => true], [$trend->toArray()]);
    }

    private function withExplanation(ZaakiySkillResult $result, IntentFrame $intent, ZaakiyExecutionContext $execution, ZaakiyReadSkill $skill): ZaakiySkillResult
    {
        if ($intent->comparisonPeriod === null || $result->comparisons === []) {
            return $this->explanationWarning($result, 'COMPARISON_REQUIRED', 'A comparison period is required to explain a metric change.');
        }
        if (! $skill instanceof CollectionsHealthSkill && ! $skill instanceof MaintenanceIntelligenceSkill) {
            return $this->explanationWarning($result, 'METRIC_EXPLANATION_UNAVAILABLE', 'Verified driver data is not available for this metric.');
        }
        if (($result->meta['financial_included'] ?? true) === false) {
            return $this->explanationWarning($result, 'FINANCIAL_DATA_RESTRICTED', 'Collections explanations require accounts.view.');
        }
        $metricId = $skill instanceof CollectionsHealthSkill ? 'collections.collected_amount' : 'maintenance.completed_work_order_count';
        if ($skill instanceof MaintenanceIntelligenceSkill && $intent->intent !== 'maintenance_completion') {
            return $this->explanationWarning($result, 'HISTORICAL_SNAPSHOT_UNAVAILABLE', 'Historical maintenance backlog state is not available for a reliable explanation.');
        }
        $comparison = collect($result->comparisons)->firstWhere('metric', $metricId);
        if (! $comparison) {
            return $this->explanationWarning($result, 'METRIC_EXPLANATION_UNAVAILABLE', 'This metric is not additively decomposable by the available verified data.');
        }
        $drivers = $skill instanceof CollectionsHealthSkill
            ? $skill->explanationDrivers($execution->branchId(), $comparison['current_range'], $comparison['comparison_range'])
            : $skill->explanationDrivers($execution->branchId(), $comparison['current_range'], $comparison['comparison_range'], $intent->filters);
        $label = $skill instanceof CollectionsHealthSkill ? 'Collected amount' : 'Completed work orders';
        $explanation = $this->explanationEngine->explainAdditive($metricId, $label, $comparison['current_value'], $comparison['comparison_value'], $comparison['current_range'], $comparison['comparison_range'], $drivers)->toArray();
        if ($explanation['absolute_delta'] === 0) {
            return $this->explanationWarning($result, 'NO_METRIC_CHANGE', 'The metric did not change between the two periods.');
        }

        return new ZaakiySkillResult($result->intent, $result->subject, $result->summaryMetrics, $result->records, $result->breakdowns, $result->comparisons, $result->warnings, $result->sources, $result->navigation, $result->suggestedFollowups, $result->timeRange, $result->meta + ['explanation_requested' => true], $result->trends, [$explanation]);
    }

    private function explanationWarning(ZaakiySkillResult $result, string $code, string $message): ZaakiySkillResult
    {
        return new ZaakiySkillResult($result->intent, $result->subject, $result->summaryMetrics, $result->records, $result->breakdowns, $result->comparisons, [...$result->warnings, ['code' => $code, 'message' => $message]], $result->sources, $result->navigation, $result->suggestedFollowups, $result->timeRange, $result->meta + ['explanation_requested' => true], $result->trends, $result->explanations);
    }

    private function withAnomalies(ZaakiySkillResult $result, IntentFrame $intent): ZaakiySkillResult
    {
        $anomalies = [];
        $partial = $this->partialRange($intent->timeRange);
        foreach ($result->comparisons as $comparison) {
            $drop = match ($comparison['metric'] ?? null) {
                'collections.collected_amount' => $this->anomalyEngine->significantDrop($comparison, 'COLLECTION_DROP_SIGNIFICANT', 'percentage_delta <= -25% and absolute_delta <= -AED 10,000', ['percentage' => -25, 'absolute' => -10000], $partial),
                'maintenance.completed_work_order_count' => $this->anomalyEngine->significantDrop($comparison, 'MAINTENANCE_COMPLETION_DROP', 'percentage_delta <= -30% and absolute_delta <= -5', ['percentage' => -30, 'absolute' => -5], $partial),
                default => null,
            };
            $increase = match ($comparison['metric'] ?? null) {
                'properties.vacant_count' => $this->anomalyEngine->significantIncrease($comparison, 'VACANCY_COUNT_INCREASE', 'percentage_delta >= 20% and absolute_delta >= 3', ['percentage' => 20, 'absolute' => 3], $partial),
                'agreements.renewal_candidate_count' => $this->anomalyEngine->significantIncrease($comparison, 'RENEWAL_COUNT_SPIKE', 'percentage_delta >= 50% and absolute_delta >= 5', ['percentage' => 50, 'absolute' => 5], $partial),
                default => null,
            };
            if ($drop) {
                $anomalies[] = $drop;
            }
            if ($increase) {
                $anomalies[] = $increase;
            }
            if (($comparison['metric'] ?? null) === 'collections.collected_amount') {
                $zero = $this->anomalyEngine->zeroInPeriod($comparison, 'COLLECTION_ZERO_IN_ACTIVE_PERIOD', 'current collected amount is zero while the comparison period was non-zero', $partial);
                if ($zero && $drop === null) {
                    $anomalies[] = $zero;
                }
            }
        }

        if (($result->meta['financial_included'] ?? true) && (int) ($result->summaryMetrics['bounced_cheque_count'] ?? 0) > 0) {
            $anomalies[] = $this->anomalyEngine->condition('BOUNCED_CHEQUE_PRESENT', 'collections.bounced_cheque_count', 'Bounced cheques', 'attention', (int) $result->summaryMetrics['bounced_cheque_count'], ['minimum' => 1], 'bounced cheque count is at least one');
        }
        foreach ($result->records as $record) {
            foreach ((array) ($record['signals'] ?? []) as $signal) {
                $mapping = match ($signal) {
                    'OWNER_COVERAGE_BEFORE_TENANT_END' => ['OWNER_COVERAGE_MISMATCH', 'agreements.attention_count', 'Owner coverage mismatch', 'critical', 'owner coverage ends before tenant agreement', 'agreement'],
                    'EXPIRED_ACTIVE_STATUS' => ['AGREEMENT_EXPIRED_ACTIVE', 'agreements.attention_count', 'Expired active agreement', 'critical', 'agreement end date is before today while status remains active', 'agreement'],
                    'BOUNCED_CHEQUE' => ['BOUNCED_CHEQUE_PRESENT', 'agreements.attention_count', 'Bounced cheque', 'attention', 'agreement has a bounced cheque', 'agreement'],
                    default => null,
                };
                if ($mapping !== null && (($result->meta['financial_included'] ?? true) || $signal !== 'BOUNCED_CHEQUE')) {
                    $anomalies[] = $this->anomalyEngine->condition(
                        $mapping[0],
                        $mapping[1],
                        $mapping[2],
                        $mapping[3],
                        1,
                        ['minimum' => 1],
                        $mapping[4],
                        [['type' => $mapping[5], 'id' => $record['id'], 'label' => $record['agreement_no'] ?? (string) $record['id']]],
                        $intent->timeRange,
                    );
                }
            }
            if (($record['type'] ?? null) === 'property' && (int) ($record['vacancy_days'] ?? 0) >= 90) {
                $anomalies[] = $this->anomalyEngine->condition('LONG_VACANCY_90_PLUS', 'properties.vacant_count', 'Long vacancy', 'attention', (int) $record['vacancy_days'], ['minimum_days' => 90], 'vacancy_days >= 90', [['type' => 'property', 'id' => $record['id'], 'label' => $record['property_code']]], $intent->timeRange);
            }
            if (($record['type'] ?? null) === 'work_order' && (int) ($record['age_days'] ?? 0) >= 60) {
                $anomalies[] = $this->anomalyEngine->condition('OPEN_WORK_ORDER_60_PLUS', 'maintenance.open_work_order_count', 'Old open work order', 'attention', (int) $record['age_days'], ['minimum_days' => 60], 'age_days >= 60', [['type' => 'work_order', 'id' => $record['id'], 'label' => $record['work_order_no']]], $intent->timeRange);
            }
            if (($record['type'] ?? null) === 'work_order' && ($record['priority'] ?? null) === 'urgent') {
                $anomalies[] = $this->anomalyEngine->condition('URGENT_OPEN_WORK_ORDER_PRESENT', 'maintenance.open_work_order_count', 'Urgent open work order', 'attention', 1, ['minimum' => 1], 'priority is urgent', [['type' => 'work_order', 'id' => $record['id'], 'label' => $record['work_order_no']]], $intent->timeRange);
            }
        }

        return new ZaakiySkillResult($result->intent, $result->subject, $result->summaryMetrics, $result->records, $result->breakdowns, $result->comparisons, $result->warnings, $result->sources, $result->navigation, $result->suggestedFollowups, $result->timeRange, $result->meta + ['anomaly_requested' => true, 'anomaly_count' => count($anomalies)], $result->trends, $result->explanations, array_map(static fn ($anomaly) => $anomaly->toArray(), $this->anomalyEngine->sortAndBound($anomalies)));
    }

    private function partialRange(?array $range): bool
    {
        return $range !== null && $range['to'] > CarbonImmutable::now(config('app.timezone'))->toDateString();
    }

    private function trendMetric(IntentFrame $intent): ?array
    {
        return match (true) {
            in_array($intent->intent, ['collections_health', 'collections_summary'], true) => (preg_match('/payment count|payments|number of payments/i', $intent->question) === 1 ? ['payment_count', 'Payment count', 'count', 'collections.payment_count'] : ['collected_amount', 'Collected amount', 'AED', 'collections.collected_amount']),
            $intent->intent === 'maintenance_completion' => ['completed_work_order_count', 'Completed work orders', 'count', 'maintenance.completed_work_order_count'],
            in_array($intent->intent, ['renewal_intelligence', 'renewal_candidates', 'renewals_due', 'renewal_attention'], true) => ['renewal_candidate_count', 'Renewal candidates', 'count', 'agreements.renewal_candidate_count'],
            in_array($intent->intent, ['vacancy_analysis', 'occupancy_summary', 'vacant_properties', 'property_availability', 'upcoming_vacancy'], true) => (in_array($intent->intent, ['occupancy_summary'], true) || preg_match('/occupancy rate|occupancy trend|occupied/i', $intent->question) === 1 ? ['occupancy_rate', 'Occupancy rate', 'percent', 'properties.occupancy_rate'] : ['vacant_property_count', 'Vacant properties', 'count', 'properties.vacant_count']),
            in_array($intent->intent, ['agreement_risk', 'agreement_attention', 'agreement_expiry', 'agreement_financial_attention'], true) => ['expiring_soon_count', 'Expiring agreements', 'count', 'agreements.expiring_count'],
            default => null,
        };
    }

    private function comparisonMetrics(IntentFrame $intent, ZaakiySkillResult $result): array
    {
        return match (true) {
            in_array($intent->intent, ['collections_health', 'collections_summary'], true) => [['collected_amount', 'collections.collected_amount', 'Collected amount', 'AED'], ['payment_count', 'collections.payment_count', 'Payment count', 'count'], ['unique_tenant_count', 'collections.unique_tenant_count', 'Unique tenants', 'count']],
            $intent->intent === 'maintenance_completion' => [['completed_work_order_count', 'maintenance.completed_work_order_count', 'Completed work orders', 'count']],
            in_array($intent->intent, ['renewal_intelligence', 'renewal_candidates', 'renewals_due', 'renewal_attention'], true) => [['renewal_candidate_count', 'agreements.renewal_candidate_count', 'Renewal candidates', 'count'], ['tenant_renewal_count', 'agreements.tenant_renewal_count', 'Tenant renewals', 'count'], ['owner_renewal_count', 'agreements.owner_renewal_count', 'Owner renewals', 'count']],
            in_array($intent->intent, ['agreement_risk', 'agreement_attention', 'agreement_expiry', 'agreement_financial_attention'], true) => [['agreement_count_needing_attention', 'agreements.attention_count', 'Agreements needing attention', 'count'], ['expiring_soon_count', 'agreements.expiring_count', 'Expiring agreements', 'count']],
            in_array($intent->intent, ['vacancy_analysis', 'occupancy_summary', 'vacant_properties', 'property_availability', 'upcoming_vacancy'], true) => [['occupied_property_count', 'properties.occupied_count', 'Occupied properties', 'count'], ['vacant_property_count', 'properties.vacant_count', 'Vacant properties', 'count'], ['occupancy_rate', 'properties.occupancy_rate', 'Occupancy rate', 'percent'], ['vacancy_rate', 'properties.vacancy_rate', 'Vacancy rate', 'percent']],
            default => [],
        };
    }

    private function resultWithWarning(ZaakiySkillResult $result, string $code, string $message): ZaakiySkillResult
    {
        return new ZaakiySkillResult($result->intent, $result->subject, $result->summaryMetrics, $result->records, $result->breakdowns, [], [...$result->warnings, ['code' => $code, 'message' => $message]], $result->sources, $result->navigation, $result->suggestedFollowups, $result->timeRange, $result->meta + ['comparison_requested' => true], $result->trends);
    }

    private function currentMonth(): array
    {
        $now = CarbonImmutable::now(config('app.timezone'));

        return ['from' => $now->startOfMonth()->toDateString(), 'to' => $now->endOfMonth()->toDateString()];
    }

    private function numeric(mixed $value): int|float
    {
        return is_numeric($value) ? (str_contains((string) $value, '.') ? (float) $value : (int) $value) : 0;
    }
}
