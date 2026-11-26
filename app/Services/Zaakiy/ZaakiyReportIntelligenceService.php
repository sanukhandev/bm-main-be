<?php

namespace App\Services\Zaakiy;

use App\Models\Branch;
use App\Models\User;
use App\Services\IntelligentReportPeriod;
use App\Services\Zaakiy\Skills\AgreementRiskSkill;
use App\Services\Zaakiy\Skills\CollectionsHealthSkill;
use App\Services\Zaakiy\Skills\MaintenanceIntelligenceSkill;
use App\Services\Zaakiy\Skills\ManagementBriefingSkill;
use App\Services\Zaakiy\Skills\RenewalIntelligenceSkill;
use App\Services\Zaakiy\Skills\VacancyAnalysisSkill;
use App\Support\Branch\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ZaakiyReportIntelligenceService
{
    public function __construct(
        private readonly ManagementBriefingSkill $briefing,
        private readonly CollectionsHealthSkill $collections,
        private readonly VacancyAnalysisSkill $vacancy,
        private readonly AgreementRiskSkill $agreementRisk,
        private readonly RenewalIntelligenceSkill $renewals,
        private readonly MaintenanceIntelligenceSkill $maintenance,
        private readonly MetricComparisonEngine $comparison,
        private readonly AnomalyDetectionEngine $anomalies,
    ) {}

    /**
     * Assemble deterministic Zaakiy executive overview and operational skill intelligence
     * for the Intelligent Report using the same branch-scoped skills that power Zaakiy chat.
     *
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public function build(
        User $user,
        BranchContext $context,
        array $branchIds,
        bool $overall,
        IntelligentReportPeriod $period,
        array $summary,
    ): array {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $in30Days = CarbonImmutable::parse($today, config('app.timezone'))->addDays(30)->toDateString();
        $in60Days = CarbonImmutable::parse($today, config('app.timezone'))->addDays(60)->toDateString();
        $requestAt = CarbonImmutable::now(config('app.timezone'))->toIso8601String();
        $currentRange = ['from' => $period->from, 'to' => $period->to];

        $branches = $overall
            ? Branch::query()->whereIn('id', $branchIds)->orderBy('name')->get()->all()
            : [$context->branch()];

        $attentionItems = [];

        // Collections aggregators
        $collectionsAvailable = false;
        $collectionsMetrics = [
            'collected_amount' => 0.0,
            'payment_count' => 0,
            'unique_tenant_count' => 0,
            'outstanding_total' => 0.0,
            'overdue_total' => 0.0,
            'tenant_count_with_outstanding' => 0,
            'tenant_count_with_overdue' => 0,
            'pending_cheque_count' => 0,
            'bounced_cheque_count' => 0,
        ];
        $agingBuckets = ['current' => 0.0, '1-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];
        $chequeStatusCounts = [];
        $overdueTenants = [];

        // Vacancy aggregators
        $vacancyMetrics = [
            'eligible_property_count' => 0,
            'occupied_property_count' => 0,
            'vacant_property_count' => 0,
            'future_occupied_property_count' => 0,
            'vacant_properties_with_open_work_orders' => 0,
            'open_work_orders_on_vacant_properties' => 0,
        ];
        $byPropertyType = [];
        $vacancyDuration = [];
        $longestVacant = [];

        // Agreement risk aggregators
        $riskMetrics = [
            'agreement_count_needing_attention' => 0,
            'expiring_soon_count' => 0,
            'overdue_balance_count' => 0,
            'bounced_cheque_agreement_count' => 0,
            'coverage_mismatch_count' => 0,
            'total_outstanding_amount' => 0.0,
            'total_overdue_amount' => 0.0,
        ];
        $riskSignals = [];
        $flaggedAgreements = [];

        // Renewals aggregators
        $renewalMetrics = [
            'renewal_candidate_count' => 0,
            'tenant_renewal_count' => 0,
            'owner_renewal_count' => 0,
            'due_within_30_days_count' => 0,
            'owner_renewals_affecting_occupied_properties' => 0,
            'renewal_with_outstanding_count' => 0,
            'renewal_with_overdue_count' => 0,
            'renewal_outstanding_amount' => 0.0,
            'renewal_overdue_amount' => 0.0,
        ];
        $renewalSignals = [];
        $renewalCandidates = [];

        // Maintenance aggregators
        $maintMetrics = [
            'open_work_order_count' => 0,
            'properties_with_open_work_orders' => 0,
            'high_priority_open_count' => 0,
            'oldest_open_age_days' => null,
            'completed_work_order_count' => 0,
        ];
        $maintAging = ['0-7' => 0, '8-14' => 0, '15-30' => 0, '31-60' => 0, '60+' => 0];
        $maintStatus = [];
        $maintPriority = [];
        $oldestWorkOrders = [];

        foreach ($branches as $branch) {
            $branchCtx = new BranchContext;
            $branchCtx->set($branch);
            $branchName = $overall ? $branch->name : null;

            $exec = static fn (string $intent, ?array $range = null, array $filters = []): ZaakiyExecutionContext => new ZaakiyExecutionContext(
                $user,
                $branchCtx,
                new IntentFrame($intent, [$intent], 'aggregate', question: $intent, filters: $filters, timeRange: $range),
                $requestAt,
            );

            // 1. Management briefing attention items
            $briefingRes = $this->briefing->execute($exec('management_briefing', $currentRange));
            foreach ($briefingRes->anomalies as $item) {
                $attentionItems[] = [
                    'code' => (string) ($item['code'] ?? 'ATTENTION'),
                    'label' => (string) ($item['label'] ?? 'Attention item'),
                    'severity' => (string) ($item['severity'] ?? 'attention'),
                    'metric' => (string) ($item['metric'] ?? ''),
                    'observed_value' => $item['observed_value'] ?? 0,
                    'rule' => (string) ($item['rule'] ?? ''),
                    'branch' => $branchName,
                    'references' => array_slice((array) ($item['references'] ?? []), 0, 3),
                ];
            }

            // 2. Collections health + overdue tenants
            $colSummary = $this->collections->execute($exec('collections_summary', $currentRange));
            if (($colSummary->meta['financial_included'] ?? true) !== false) {
                $collectionsAvailable = true;
                $colOverdue = $this->collections->execute($exec('overdue_receivables', $currentRange));

                $collectionsMetrics['collected_amount'] += (float) ($colSummary->summaryMetrics['collected_amount'] ?? 0);
                $collectionsMetrics['payment_count'] += (int) ($colSummary->summaryMetrics['payment_count'] ?? 0);
                $collectionsMetrics['unique_tenant_count'] += (int) ($colSummary->summaryMetrics['unique_tenant_count'] ?? 0);
                $collectionsMetrics['outstanding_total'] += (float) ($colSummary->summaryMetrics['outstanding_total'] ?? 0);
                $collectionsMetrics['tenant_count_with_outstanding'] += (int) ($colSummary->summaryMetrics['tenant_count_with_outstanding'] ?? 0);
                $collectionsMetrics['pending_cheque_count'] += (int) ($colSummary->summaryMetrics['pending_cheque_count'] ?? 0);
                $collectionsMetrics['bounced_cheque_count'] += (int) ($colSummary->summaryMetrics['bounced_cheque_count'] ?? 0);
                $collectionsMetrics['overdue_total'] += (float) ($colOverdue->summaryMetrics['overdue_total'] ?? 0);
                $collectionsMetrics['tenant_count_with_overdue'] += (int) ($colOverdue->summaryMetrics['tenant_count_with_overdue'] ?? 0);

                foreach ((array) ($colSummary->breakdowns['aging'] ?? []) as $row) {
                    $bucket = (string) ($row['bucket'] ?? 'current');
                    $agingBuckets[$bucket] = ($agingBuckets[$bucket] ?? 0.0) + (float) ($row['amount'] ?? 0);
                }
                foreach ((array) ($colSummary->breakdowns['cheque_status'] ?? []) as $row) {
                    $status = (string) ($row['status'] ?? 'unknown');
                    $chequeStatusCounts[$status] = ($chequeStatusCounts[$status] ?? 0) + (int) ($row['count'] ?? 0);
                }
                foreach ($colOverdue->records as $row) {
                    $overdueTenants[] = [
                        'id' => (int) ($row['id'] ?? 0),
                        'customer_code' => (string) ($row['customer_code'] ?? ''),
                        'display_name' => (string) ($row['display_name'] ?? ''),
                        'outstanding' => $this->money((float) ($row['outstanding'] ?? 0)),
                        'overdue' => $this->money((float) ($row['overdue'] ?? 0)),
                        'agreement_count' => (int) ($row['agreement_count'] ?? 0),
                        'branch' => $branchName,
                    ];
                }
            }

            // 3. Vacancy & leasing intelligence
            $vacRes = $this->vacancy->execute($exec('vacant_properties', $currentRange, ['longest_vacant' => true, 'as_of' => $today]));
            $vacancyMetrics['eligible_property_count'] += (int) ($vacRes->summaryMetrics['eligible_property_count'] ?? 0);
            $vacancyMetrics['occupied_property_count'] += (int) ($vacRes->summaryMetrics['occupied_property_count'] ?? 0);
            $vacancyMetrics['vacant_property_count'] += (int) ($vacRes->summaryMetrics['vacant_property_count'] ?? 0);
            $vacancyMetrics['future_occupied_property_count'] += (int) ($vacRes->summaryMetrics['future_occupied_property_count'] ?? 0);
            $vacancyMetrics['vacant_properties_with_open_work_orders'] += (int) ($vacRes->summaryMetrics['vacant_properties_with_open_work_orders'] ?? 0);
            $vacancyMetrics['open_work_orders_on_vacant_properties'] += (int) ($vacRes->summaryMetrics['open_work_orders_on_vacant_properties'] ?? 0);

            foreach ((array) ($vacRes->breakdowns['occupancy_by_property_type'] ?? []) as $row) {
                $type = (string) ($row['property_type'] ?? 'other');
                $byPropertyType[$type] ??= ['property_type' => $type, 'total' => 0, 'occupied' => 0, 'vacant' => 0];
                $byPropertyType[$type]['total'] += (int) ($row['total'] ?? 0);
                $byPropertyType[$type]['occupied'] += (int) ($row['occupied'] ?? 0);
                $byPropertyType[$type]['vacant'] += (int) ($row['vacant'] ?? 0);
            }
            foreach ((array) ($vacRes->breakdowns['vacancy_duration'] ?? []) as $row) {
                $bucket = (string) ($row['bucket'] ?? 'unknown');
                $vacancyDuration[$bucket] = ($vacancyDuration[$bucket] ?? 0) + (int) ($row['property_count'] ?? 0);
            }
            foreach ($vacRes->records as $row) {
                $longestVacant[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'property_code' => (string) ($row['property_code'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'property_type' => (string) ($row['property_type'] ?? ''),
                    'vacancy_days' => $row['vacancy_days'] === null ? null : (int) $row['vacancy_days'],
                    'open_work_order_count' => (int) ($row['open_work_order_count'] ?? 0),
                    'available_for_leasing' => (bool) ($row['available_for_leasing'] ?? false),
                    'branch' => $branchName,
                ];
            }

            // 4. Agreement risk & coverage signals (30-day window)
            $riskRes = $this->agreementRisk->execute($exec('agreement_risk', ['from' => $today, 'to' => $in30Days]));
            $riskMetrics['agreement_count_needing_attention'] += (int) ($riskRes->summaryMetrics['agreement_count_needing_attention'] ?? 0);
            $riskMetrics['expiring_soon_count'] += (int) ($riskRes->summaryMetrics['expiring_soon_count'] ?? 0);
            $riskMetrics['overdue_balance_count'] += (int) ($riskRes->summaryMetrics['overdue_balance_count'] ?? 0);
            $riskMetrics['bounced_cheque_agreement_count'] += (int) ($riskRes->summaryMetrics['bounced_cheque_agreement_count'] ?? 0);
            $riskMetrics['coverage_mismatch_count'] += (int) ($riskRes->summaryMetrics['coverage_mismatch_count'] ?? 0);
            $riskMetrics['total_outstanding_amount'] += (float) ($riskRes->summaryMetrics['total_outstanding_amount'] ?? 0);
            $riskMetrics['total_overdue_amount'] += (float) ($riskRes->summaryMetrics['total_overdue_amount'] ?? 0);

            foreach ((array) ($riskRes->breakdowns['attention_signals'] ?? []) as $row) {
                $code = (string) ($row['code'] ?? '');
                if ($code !== '') {
                    $riskSignals[$code] = ($riskSignals[$code] ?? 0) + (int) ($row['agreement_count'] ?? 0);
                }
            }
            foreach ($riskRes->records as $row) {
                $type = (string) ($row['type'] ?? 'tenant_agreement');
                $flaggedAgreements[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'type' => $type,
                    'agreement_no' => (string) ($row['agreement_no'] ?? ''),
                    'party_name' => (string) ($row['party']['display_name'] ?? ''),
                    'properties' => $this->cleanPropertyList($row['properties'] ?? null),
                    'end_date' => (string) ($row['end_date'] ?? ''),
                    'days_until_expiry' => (int) ($row['days_until_expiry'] ?? 0),
                    'signals' => array_values((array) ($row['signals'] ?? [])),
                    'overdue' => $this->money((float) ($row['overdue'] ?? 0)),
                    'outstanding' => $this->money((float) ($row['outstanding'] ?? 0)),
                    'route' => '/app/'.($type === 'owner_agreement' ? 'owner-agreements' : 'tenant-agreements').'/'.(int) ($row['id'] ?? 0),
                    'branch' => $branchName,
                ];
            }

            // 5. Renewal pipeline intelligence (60-day horizon)
            $renRes = $this->renewals->execute($exec('renewal_intelligence', ['from' => $today, 'to' => $in60Days]));
            $renewalMetrics['renewal_candidate_count'] += (int) ($renRes->summaryMetrics['renewal_candidate_count'] ?? 0);
            $renewalMetrics['tenant_renewal_count'] += (int) ($renRes->summaryMetrics['tenant_renewal_count'] ?? 0);
            $renewalMetrics['owner_renewal_count'] += (int) ($renRes->summaryMetrics['owner_renewal_count'] ?? 0);
            $renewalMetrics['due_within_30_days_count'] += (int) ($renRes->summaryMetrics['due_within_30_days_count'] ?? 0);
            $renewalMetrics['owner_renewals_affecting_occupied_properties'] += (int) ($renRes->summaryMetrics['owner_renewals_affecting_occupied_properties'] ?? 0);
            $renewalMetrics['renewal_with_outstanding_count'] += (int) ($renRes->summaryMetrics['renewal_with_outstanding_count'] ?? 0);
            $renewalMetrics['renewal_with_overdue_count'] += (int) ($renRes->summaryMetrics['renewal_with_overdue_count'] ?? 0);
            $renewalMetrics['renewal_outstanding_amount'] += (float) ($renRes->summaryMetrics['renewal_outstanding_amount'] ?? 0);
            $renewalMetrics['renewal_overdue_amount'] += (float) ($renRes->summaryMetrics['renewal_overdue_amount'] ?? 0);

            foreach ((array) ($renRes->breakdowns['renewal_signals'] ?? []) as $row) {
                $code = (string) ($row['code'] ?? '');
                if ($code !== '') {
                    $renewalSignals[$code] = ($renewalSignals[$code] ?? 0) + (int) ($row['agreement_count'] ?? 0);
                }
            }
            foreach ($renRes->records as $row) {
                $type = (string) ($row['type'] ?? 'tenant_agreement');
                $renewalCandidates[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'type' => $type,
                    'agreement_no' => (string) ($row['agreement_no'] ?? ''),
                    'party_name' => (string) ($row['party']['display_name'] ?? ''),
                    'properties' => $this->cleanPropertyList($row['properties'] ?? null),
                    'end_date' => (string) ($row['end_date'] ?? ''),
                    'days_until_expiry' => (int) ($row['days_until_expiry'] ?? 0),
                    'renewal_signals' => array_values((array) ($row['renewal_signals'] ?? [])),
                    'overdue' => $this->money((float) ($row['overdue'] ?? 0)),
                    'outstanding' => $this->money((float) ($row['outstanding'] ?? 0)),
                    'occupied_impact' => (int) ($row['portfolio_impact']['occupied_property_count'] ?? 0),
                    'route' => '/app/'.($type === 'owner_agreement' ? 'owner-agreements' : 'tenant-agreements').'/'.(int) ($row['id'] ?? 0),
                    'branch' => $branchName,
                ];
            }

            // 6. Maintenance backlog & completion intelligence
            $maintRes = $this->maintenance->execute($exec('maintenance_intelligence', $currentRange));
            $maintDoneRes = $this->maintenance->execute($exec('maintenance_completion', $currentRange));
            $maintMetrics['open_work_order_count'] += (int) ($maintRes->summaryMetrics['open_work_order_count'] ?? 0);
            $maintMetrics['properties_with_open_work_orders'] += (int) ($maintRes->summaryMetrics['properties_with_open_work_orders'] ?? 0);
            $maintMetrics['high_priority_open_count'] += (int) ($maintRes->summaryMetrics['high_priority_open_count'] ?? 0);
            $maintMetrics['completed_work_order_count'] += (int) ($maintDoneRes->summaryMetrics['completed_work_order_count'] ?? 0);
            if (isset($maintRes->summaryMetrics['oldest_open_age_days'])) {
                $age = (int) $maintRes->summaryMetrics['oldest_open_age_days'];
                $maintMetrics['oldest_open_age_days'] = $maintMetrics['oldest_open_age_days'] === null
                    ? $age
                    : max($maintMetrics['oldest_open_age_days'], $age);
            }

            foreach ((array) ($maintRes->breakdowns['maintenance_aging'] ?? []) as $row) {
                $bucket = (string) ($row['bucket'] ?? '0-7');
                $maintAging[$bucket] = ($maintAging[$bucket] ?? 0) + (int) ($row['count'] ?? 0);
            }
            foreach ((array) ($maintRes->breakdowns['status'] ?? []) as $row) {
                $status = (string) ($row['status'] ?? 'open');
                $maintStatus[$status] = ($maintStatus[$status] ?? 0) + (int) ($row['count'] ?? 0);
            }
            foreach ((array) ($maintRes->breakdowns['priority'] ?? []) as $row) {
                $priority = (string) ($row['priority'] ?? 'medium');
                $maintPriority[$priority] = ($maintPriority[$priority] ?? 0) + (int) ($row['count'] ?? 0);
            }
            foreach ($maintRes->records as $row) {
                $oldestWorkOrders[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'work_order_no' => (string) ($row['work_order_no'] ?? ''),
                    'title' => (string) ($row['title'] ?? ''),
                    'priority' => (string) ($row['priority'] ?? 'medium'),
                    'status' => (string) ($row['status'] ?? 'open'),
                    'age_days' => (int) ($row['age_days'] ?? 0),
                    'property_code' => (string) ($row['property_code'] ?? ''),
                    'branch' => $branchName,
                ];
            }
        }

        // Compute period-over-period comparisons using IntelligentReportPeriod::comparisonFrom/To
        $periodComparisons = $this->periodComparisons($branchIds, $period, $summary, $maintMetrics['completed_work_order_count']);

        // Add significant income-drop anomaly if detected by AnomalyDetectionEngine
        if (isset($periodComparisons[0])) {
            $drop = $this->anomalies->significantDrop(
                $periodComparisons[0],
                'OPERATING_INCOME_DROP',
                'operating income decreased by at least 25% vs previous period',
                ['percentage' => -25, 'absolute' => -1000],
            );
            if ($drop !== null) {
                $attentionItems[] = [
                    'code' => $drop->code,
                    'label' => 'Operating income decline vs prior period',
                    'severity' => $drop->severity,
                    'metric' => $drop->metric,
                    'observed_value' => $drop->observedValue,
                    'rule' => $drop->rule,
                    'branch' => null,
                    'references' => [],
                ];
            }
        }

        // Finalize merged rates & sort/bound ranked lists
        $eligible = $vacancyMetrics['eligible_property_count'];
        $vacancyMetrics['occupancy_rate'] = $eligible > 0 ? round(($vacancyMetrics['occupied_property_count'] / $eligible) * 100, 2) : 0.0;
        $vacancyMetrics['vacancy_rate'] = $eligible > 0 ? round(($vacancyMetrics['vacant_property_count'] / $eligible) * 100, 2) : 0.0;

        usort($overdueTenants, static fn (array $a, array $b): int => (float) $b['overdue'] <=> (float) $a['overdue']);
        usort($longestVacant, static fn (array $a, array $b): int => ($b['vacancy_days'] ?? -1) <=> ($a['vacancy_days'] ?? -1));
        usort($flaggedAgreements, static fn (array $a, array $b): int => [$a['days_until_expiry'], -(float) $a['overdue']] <=> [$b['days_until_expiry'], -(float) $b['overdue']]);
        usort($renewalCandidates, static fn (array $a, array $b): int => [$a['days_until_expiry'], -(float) $a['overdue']] <=> [$b['days_until_expiry'], -(float) $b['overdue']]);
        usort($oldestWorkOrders, static fn (array $a, array $b): int => $b['age_days'] <=> $a['age_days']);

        $attentionItems = $this->dedupeAndSortAttention($attentionItems, 6);

        $skills = [
            'collections' => [
                'title' => 'Collections Health',
                'status' => $collectionsAvailable ? 'available' : 'restricted',
                'metrics' => [
                    'collected_amount' => $this->money($collectionsMetrics['collected_amount']),
                    'payment_count' => $collectionsMetrics['payment_count'],
                    'unique_tenant_count' => $collectionsMetrics['unique_tenant_count'],
                    'outstanding_total' => $this->money($collectionsMetrics['outstanding_total']),
                    'overdue_total' => $this->money($collectionsMetrics['overdue_total']),
                    'tenant_count_with_outstanding' => $collectionsMetrics['tenant_count_with_outstanding'],
                    'tenant_count_with_overdue' => $collectionsMetrics['tenant_count_with_overdue'],
                    'pending_cheque_count' => $collectionsMetrics['pending_cheque_count'],
                    'bounced_cheque_count' => $collectionsMetrics['bounced_cheque_count'],
                ],
                'aging' => array_map(
                    fn (string $bucket, float $amount): array => ['bucket' => $bucket, 'amount' => $this->money($amount)],
                    array_keys($agingBuckets),
                    array_values($agingBuckets),
                ),
                'cheque_status' => array_map(
                    static fn (string $status, int $count): array => ['status' => $status, 'count' => $count],
                    array_keys($chequeStatusCounts),
                    array_values($chequeStatusCounts),
                ),
                'top_overdue_tenants' => array_slice($overdueTenants, 0, 5),
                'navigation' => '/app/accounts/inward',
                'ask_zaakiy' => 'Show overdue tenants and collection health for this period',
            ],
            'vacancy' => [
                'title' => 'Vacancy & Leasing',
                'status' => 'available',
                'metrics' => $vacancyMetrics,
                'by_property_type' => array_values($byPropertyType),
                'vacancy_duration' => array_map(
                    static fn (string $bucket, int $count): array => ['bucket' => $bucket, 'property_count' => $count],
                    array_keys($vacancyDuration),
                    array_values($vacancyDuration),
                ),
                'longest_vacant' => array_slice($longestVacant, 0, 5),
                'navigation' => '/app/properties',
                'ask_zaakiy' => 'Which properties have been vacant longest and need attention?',
            ],
            'agreement_risk' => [
                'title' => 'Agreement Risk & Coverage',
                'status' => 'available',
                'metrics' => [
                    ...$riskMetrics,
                    'total_outstanding_amount' => $this->money($riskMetrics['total_outstanding_amount']),
                    'total_overdue_amount' => $this->money($riskMetrics['total_overdue_amount']),
                ],
                'signals' => array_map(
                    static fn (string $code, int $count): array => ['code' => $code, 'agreement_count' => $count],
                    array_keys($riskSignals),
                    array_values($riskSignals),
                ),
                'flagged_agreements' => array_slice($flaggedAgreements, 0, 5),
                'navigation' => '/app/reports/agreement-expiry',
                'ask_zaakiy' => 'Which agreements need urgent attention or have coverage mismatches?',
            ],
            'renewals' => [
                'title' => 'Renewal Pipeline (60 Days)',
                'status' => 'available',
                'metrics' => [
                    ...$renewalMetrics,
                    'renewal_outstanding_amount' => $this->money($renewalMetrics['renewal_outstanding_amount']),
                    'renewal_overdue_amount' => $this->money($renewalMetrics['renewal_overdue_amount']),
                ],
                'signals' => array_map(
                    static fn (string $code, int $count): array => ['code' => $code, 'agreement_count' => $count],
                    array_keys($renewalSignals),
                    array_values($renewalSignals),
                ),
                'candidates' => array_slice($renewalCandidates, 0, 5),
                'navigation' => '/app/reports/agreement-expiry',
                'ask_zaakiy' => 'Show renewals due in the next 60 days with outstanding balances',
            ],
            'maintenance' => [
                'title' => 'Maintenance & Backlog',
                'status' => 'available',
                'metrics' => [
                    ...$maintMetrics,
                    'maintenance_expenditure' => (string) ($summary['maintenance_expenditure'] ?? '0.00'),
                    'petty_cash_expenditure' => (string) ($summary['petty_cash_expenditure'] ?? '0.00'),
                ],
                'aging' => array_map(
                    static fn (string $bucket, int $count): array => ['bucket' => $bucket, 'count' => $count],
                    array_keys($maintAging),
                    array_values($maintAging),
                ),
                'by_status' => array_map(
                    static fn (string $status, int $count): array => ['status' => $status, 'count' => $count],
                    array_keys($maintStatus),
                    array_values($maintStatus),
                ),
                'by_priority' => array_map(
                    static fn (string $priority, int $count): array => ['priority' => $priority, 'count' => $count],
                    array_keys($maintPriority),
                    array_values($maintPriority),
                ),
                'oldest_open' => array_slice($oldestWorkOrders, 0, 5),
                'navigation' => '/app/maintenance/work-orders',
                'ask_zaakiy' => 'Show the oldest open and high-priority maintenance work orders',
            ],
        ];

        $scopeLabel = $overall ? 'Overall Business' : $context->branch()->name;

        return [
            'overview' => [
                'headline' => $this->headline($scopeLabel, $summary, $vacancyMetrics, count($attentionItems)),
                'highlights' => $this->highlights($summary, $skills, $periodComparisons),
                'attention_items' => $attentionItems,
                'period_comparisons' => $periodComparisons,
                'suggested_prompts' => $this->suggestedPrompts($skills, $period),
            ],
            'skills' => $skills,
        ];
    }

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $summary
     * @return array<int, array<string, mixed>>
     */
    private function periodComparisons(array $branchIds, IntelligentReportPeriod $period, array $summary, int $completedWorkOrders): array
    {
        if (! $period->comparisonFrom || ! $period->comparisonTo) {
            return [];
        }

        $currentRange = ['from' => $period->from, 'to' => $period->to];
        $comparisonRange = ['from' => $period->comparisonFrom, 'to' => $period->comparisonTo];

        $prevTx = DB::table('account_transactions')
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'posted')
            ->whereBetween('transaction_date', [$period->comparisonFrom, $period->comparisonTo]);

        $prevIncome = (float) (clone $prevTx)->where('direction', 'inward')->sum('amount');
        $prevCost = (float) (clone $prevTx)->where('direction', 'outward')->sum('amount');
        $prevProfit = $prevIncome - $prevCost;

        $prevCompleted = (int) DB::table('work_orders')
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$period->comparisonFrom.' 00:00:00', $period->comparisonTo.' 23:59:59'])
            ->count();

        return [
            $this->comparison->compare('financials.operating_income', 'Operating Income', (float) ($summary['operating_income'] ?? 0), $prevIncome, 'AED', $currentRange, $comparisonRange)->toArray(),
            $this->comparison->compare('financials.operating_cost', 'Operating Cost', (float) ($summary['operating_cost'] ?? 0), $prevCost, 'AED', $currentRange, $comparisonRange)->toArray(),
            $this->comparison->compare('financials.operational_profit_loss', 'Net Operational Result', (float) ($summary['operational_profit_loss'] ?? 0), $prevProfit, 'AED', $currentRange, $comparisonRange)->toArray(),
            $this->comparison->compare('maintenance.completed_work_order_count', 'Completed Work Orders', $completedWorkOrders, $prevCompleted, 'count', $currentRange, $comparisonRange)->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $vacancyMetrics
     */
    private function headline(string $scopeLabel, array $summary, array $vacancyMetrics, int $attentionCount): string
    {
        $profit = (float) ($summary['operational_profit_loss'] ?? 0);
        $direction = $profit >= 0 ? 'surplus' : 'deficit';
        $margin = $summary['operating_margin_percent'] !== null ? ' ('.$summary['operating_margin_percent'].'% margin)' : '';
        $occupancy = $vacancyMetrics['eligible_property_count'] > 0
            ? $vacancyMetrics['occupancy_rate'].'% occupancy across '.$vacancyMetrics['eligible_property_count'].' active units'
            : 'no active units tracked';
        $attentionPhrase = $attentionCount === 0
            ? 'no priority operational anomalies flagged'
            : $attentionCount.' priority attention '.($attentionCount === 1 ? 'signal' : 'signals').' flagged';

        return sprintf(
            '%s recorded an operational %s of AED %s%s with %s and %s.',
            $scopeLabel,
            $direction,
            number_format(abs($profit), 2),
            $margin,
            $occupancy,
            $attentionPhrase,
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $skills
     * @param  array<int, array<string, mixed>>  $comparisons
     * @return array<int, string>
     */
    private function highlights(array $summary, array $skills, array $comparisons): array
    {
        $highlights = [];

        $incomeComp = $comparisons[0] ?? null;
        $deltaText = '';
        if ($incomeComp && $incomeComp['percentage_delta'] !== null) {
            $sign = $incomeComp['percentage_delta'] >= 0 ? '+' : '';
            $deltaText = sprintf(' (%s%s%% vs prior period)', $sign, $incomeComp['percentage_delta']);
        }
        $highlights[] = sprintf(
            'Operating income reached AED %s against AED %s in operating cost%s.',
            number_format((float) ($summary['operating_income'] ?? 0), 2),
            number_format((float) ($summary['operating_cost'] ?? 0), 2),
            $deltaText,
        );

        $col = $skills['collections']['metrics'];
        $highlights[] = sprintf(
            'Collections: AED %s overdue across %d tenant(s); %d pending and %d bounced cheque(s).',
            number_format((float) $col['overdue_total'], 2),
            (int) $col['tenant_count_with_overdue'],
            (int) $col['pending_cheque_count'],
            (int) $col['bounced_cheque_count'],
        );

        $vac = $skills['vacancy']['metrics'];
        $highlights[] = sprintf(
            'Portfolio capacity: %d occupied and %d vacant unit(s) (%s%% occupancy)%s.',
            (int) $vac['occupied_property_count'],
            (int) $vac['vacant_property_count'],
            $vac['occupancy_rate'],
            (int) $vac['vacant_properties_with_open_work_orders'] > 0
                ? ', including '.$vac['vacant_properties_with_open_work_orders'].' vacant unit(s) with open maintenance'
                : '',
        );

        $risk = $skills['agreement_risk']['metrics'];
        $ren = $skills['renewals']['metrics'];
        $highlights[] = sprintf(
            'Contracts & renewals: %d agreement(s) flagged for attention (%d coverage mismatch) and %d renewal candidate(s) within 60 days.',
            (int) $risk['agreement_count_needing_attention'],
            (int) $risk['coverage_mismatch_count'],
            (int) $ren['renewal_candidate_count'],
        );

        $maint = $skills['maintenance']['metrics'];
        $oldestNote = $maint['oldest_open_age_days'] !== null ? ', oldest open '.$maint['oldest_open_age_days'].' day(s)' : '';
        $highlights[] = sprintf(
            'Maintenance: %d open work order(s) (%d high/urgent%s) and %d completed during this period.',
            (int) $maint['open_work_order_count'],
            (int) $maint['high_priority_open_count'],
            $oldestNote,
            (int) $maint['completed_work_order_count'],
        );

        return $highlights;
    }

    /**
     * @param  array<string, mixed>  $skills
     * @return array<int, string>
     */
    private function suggestedPrompts(array $skills, IntelligentReportPeriod $period): array
    {
        $prompts = [
            'Give me a full management briefing for this branch',
        ];

        if ((float) ($skills['collections']['metrics']['overdue_total'] ?? 0) > 0) {
            $prompts[] = 'Which tenants have the highest overdue balances right now?';
        }
        if ((int) ($skills['vacancy']['metrics']['vacant_property_count'] ?? 0) > 0) {
            $prompts[] = 'Which properties have been vacant longest and are ready for leasing?';
        }
        if ((int) ($skills['renewals']['metrics']['renewal_candidate_count'] ?? 0) > 0) {
            $prompts[] = 'Show agreements due for renewal in the next 60 days with overdue balances';
        }
        if ((int) ($skills['maintenance']['metrics']['open_work_order_count'] ?? 0) > 0) {
            $prompts[] = 'Show the oldest open and urgent maintenance work orders';
        }
        if (count($prompts) < 5) {
            $prompts[] = 'Compare this period collections and maintenance completion with '.$period->comparisonFrom.' to '.$period->comparisonTo;
        }

        return array_slice(array_values(array_unique($prompts)), 0, 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function dedupeAndSortAttention(array $items, int $limit): array
    {
        $priority = ['critical' => 0, 'attention' => 1, 'info' => 2];
        $unique = [];
        foreach ($items as $item) {
            $refId = $item['references'][0]['id'] ?? '';
            $key = ($item['branch'] ?? '').':'.$item['code'].':'.$refId;
            $unique[$key] = $item;
        }
        $list = array_values($unique);
        usort($list, static fn (array $a, array $b): int => ($priority[$a['severity']] ?? 9) <=> ($priority[$b['severity']] ?? 9)
            ?: strcmp((string) $a['code'], (string) $b['code']));

        return array_slice($list, 0, $limit);
    }

    private function cleanPropertyList(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $part): bool => $part !== ''));

        return $parts === [] ? null : implode(', ', array_unique($parts));
    }

    private function money(float|int $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
