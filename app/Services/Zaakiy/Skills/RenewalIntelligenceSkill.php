<?php

namespace App\Services\Zaakiy\Skills;

use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Services\Zaakiy\ZaakiyReadSkill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RenewalIntelligenceSkill implements ZaakiyReadSkill
{
    private const DEFAULT_HORIZON_DAYS = 60;

    private const MAX_RECORDS = 25;

    public function __construct(private readonly AgreementRiskSkill $risk) {}

    public function supports(IntentFrame $intent): bool
    {
        return in_array($intent->intent, ['renewal_intelligence', 'renewal_candidates', 'renewals_due', 'renewal_attention'], true)
            || in_array('renewal_intelligence', $intent->modules, true);
    }

    public function execute(ZaakiyExecutionContext $context): ZaakiySkillResult
    {
        $financial = $context->can('accounts.view');
        $range = $context->intent->timeRange ?: $this->defaultRange();
        $riskIntent = new IntentFrame(
            intent: 'agreement_risk',
            modules: ['agreement_risk'],
            operation: 'search',
            filters: $context->intent->filters,
            timeRange: $range,
            question: $context->intent->question,
        );
        $riskContext = new ZaakiyExecutionContext(
            $context->user,
            $context->branch,
            $riskIntent,
            $context->requestAt,
            $context->conversation,
        );
        $riskResult = $this->risk->execute($riskContext);
        $records = array_values(array_filter(array_map(
            fn (array $record): ?array => $this->renewalRecord($record, $range, $financial),
            $riskResult->records
        )));
        $records = $this->addOwnerImpact($records, $context->branchId());
        $records = $this->filter($records, $context->intent->filters, $financial);
        usort($records, fn (array $a, array $b): int => $this->sortKey($a) <=> $this->sortKey($b));
        $records = array_slice($records, 0, self::MAX_RECORDS);

        $signalCounts = [];
        $outstanding = 0.0;
        $overdue = 0.0;
        foreach ($records as $record) {
            foreach ($record['renewal_signals'] as $signal) {
                $signalCounts[$signal] = ($signalCounts[$signal] ?? 0) + 1;
            }
            $outstanding += (float) ($record['outstanding'] ?? 0);
            $overdue += (float) ($record['overdue'] ?? 0);
        }

        $metrics = [
            'renewal_candidate_count' => count($records),
            'tenant_renewal_count' => count(array_filter($records, fn (array $r): bool => $r['type'] === 'tenant_agreement')),
            'owner_renewal_count' => count(array_filter($records, fn (array $r): bool => $r['type'] === 'owner_agreement')),
            'due_within_30_days_count' => count(array_filter($records, fn (array $r): bool => ($r['days_until_expiry'] ?? 999) >= 0 && ($r['days_until_expiry'] ?? 999) <= 30)),
            'owner_renewals_affecting_occupied_properties' => count(array_filter($records, fn (array $r): bool => ($r['portfolio_impact']['occupied_property_count'] ?? 0) > 0)),
        ];
        if ($financial) {
            $metrics += [
                'renewal_with_outstanding_count' => $signalCounts['RENEWAL_WITH_OUTSTANDING'] ?? 0,
                'renewal_with_overdue_count' => $signalCounts['RENEWAL_WITH_OVERDUE'] ?? 0,
                'renewal_outstanding_amount' => number_format($outstanding, 2, '.', ''),
                'renewal_overdue_amount' => number_format($overdue, 2, '.', ''),
            ];
        }

        $breakdowns = [
            'renewal_signals' => collect($signalCounts)->map(fn (int $count, string $code): array => ['code' => $code, 'agreement_count' => $count])->values()->all(),
            'agreement_type' => [
                ['type' => 'tenant_agreement', 'agreement_count' => $metrics['tenant_renewal_count']],
                ['type' => 'owner_agreement', 'agreement_count' => $metrics['owner_renewal_count']],
            ],
        ];
        $sources = [];
        foreach ($records as $record) {
            $sources[] = ['type' => $record['type'], 'id' => $record['id'], 'label' => $record['agreement_no']];
            if (($record['party']['id'] ?? null) && ($record['party']['customer_code'] ?? null)) {
                $sources[] = ['type' => $record['type'] === 'tenant_agreement' ? 'tenant' : 'owner', 'id' => $record['party']['id'], 'label' => $record['party']['customer_code']];
            }
        }

        $warnings = $financial ? [] : [[
            'code' => 'FINANCIAL_DATA_RESTRICTED',
            'message' => 'Financial renewal signals require accounts.view.',
        ]];

        return new ZaakiySkillResult(
            intent: $context->intent->intent,
            subject: 'Renewal candidates',
            summaryMetrics: $metrics,
            records: $records,
            breakdowns: $breakdowns,
            warnings: $warnings,
            sources: $sources,
            navigation: [['label' => 'View agreement expiry report', 'route' => '/app/reports/agreement-expiry', 'query' => []]],
            suggestedFollowups: $this->suggestions($financial),
            timeRange: $range,
            meta: [
                'result_type' => 'renewal_intelligence',
                'branch_scoped' => true,
                'financial_included' => $financial,
                'deterministic' => true,
                'read_only' => true,
                'default_horizon_days' => self::DEFAULT_HORIZON_DAYS,
                'record_count' => count($records),
            ],
        );
    }

    private function addOwnerImpact(array $records, int $branchId): array
    {
        $ownerIds = array_values(array_unique(array_map(
            fn (array $record): int => (int) $record['id'],
            array_filter($records, fn (array $record): bool => $record['type'] === 'owner_agreement')
        )));
        if ($ownerIds === []) {
            return $records;
        }

        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $impact = DB::table('owner_agreement_properties as owner_links')
            ->leftJoin('tenant_agreement_properties as tenant_links', function ($join): void {
                $join->on('tenant_links.property_id', '=', 'owner_links.property_id')
                    ->on('tenant_links.branch_id', '=', 'owner_links.branch_id');
            })
            ->leftJoin('tenant_agreements as tenants', function ($join): void {
                $join->on('tenants.id', '=', 'tenant_links.tenant_agreement_id')
                    ->on('tenants.branch_id', '=', 'tenant_links.branch_id');
            })
            ->where('owner_links.branch_id', $branchId)
            ->whereIn('owner_links.owner_agreement_id', $ownerIds)
            ->select('owner_links.owner_agreement_id')
            ->selectRaw('COUNT(DISTINCT owner_links.property_id) as linked_property_count')
            ->selectRaw("COUNT(DISTINCT CASE WHEN tenants.status IN ('approved', 'commenced', 'on_hold') AND tenants.start_date <= ? AND tenants.end_date >= ? THEN owner_links.property_id END) as occupied_property_count", [$today, $today])
            ->selectRaw("COUNT(DISTINCT CASE WHEN tenants.status IN ('approved', 'commenced', 'on_hold') AND tenants.start_date <= ? AND tenants.end_date >= ? THEN tenants.id END) as active_tenant_agreement_count", [$today, $today])
            ->groupBy('owner_links.owner_agreement_id')
            ->get()
            ->keyBy('owner_agreement_id');

        return array_map(function (array $record) use ($impact): array {
            if ($record['type'] !== 'owner_agreement') {
                return $record;
            }
            $row = $impact->get($record['id']);
            $record['portfolio_impact'] = [
                'linked_property_count' => (int) ($row->linked_property_count ?? 0),
                'occupied_property_count' => (int) ($row->occupied_property_count ?? 0),
                'active_tenant_agreement_count' => (int) ($row->active_tenant_agreement_count ?? 0),
            ];

            return $record;
        }, $records);
    }

    private function renewalRecord(array $record, array $range, bool $financial): ?array
    {
        $days = (int) ($record['days_until_expiry'] ?? 0);
        if ($days >= 0 && (($record['end_date'] ?? '') < $range['from'] || ($record['end_date'] ?? '') > $range['to'])) {
            return null;
        }

        $signals = [];
        if ($days < 0) {
            $signals[] = 'RENEWAL_OVERDUE';
        } else {
            $signals[] = 'RENEWAL_DUE';
            if ($days <= 30) {
                $signals[] = 'RENEWAL_DUE_SOON';
            }
        }
        foreach ($record['signals'] ?? [] as $signal) {
            $signals[] = match ($signal) {
                'OUTSTANDING_BALANCE', 'EXPIRING_WITH_OUTSTANDING' => 'RENEWAL_WITH_OUTSTANDING',
                'OVERDUE_BALANCE', 'EXPIRING_WITH_OVERDUE' => 'RENEWAL_WITH_OVERDUE',
                'BOUNCED_CHEQUE' => 'RENEWAL_WITH_BOUNCED_CHEQUE',
                'OWNER_COVERAGE_ENDING' => 'RENEWAL_OWNER_COVERAGE_ENDING',
                'OWNER_COVERAGE_BEFORE_TENANT_END' => 'RENEWAL_OWNER_COVERAGE_MISMATCH',
                default => null,
            };
        }
        $signals = array_values(array_unique(array_filter($signals)));
        $renewal = [
            'type' => $record['type'],
            'id' => $record['id'],
            'label' => $record['agreement_no'],
            'agreement_no' => $record['agreement_no'],
            'party' => $record['party'],
            'properties' => $record['properties'] ?? null,
            'status' => $record['status'],
            'start_date' => $record['start_date'],
            'end_date' => $record['end_date'],
            'days_until_expiry' => $days,
            'renewal_signals' => $signals,
            'financial_direction' => $record['financial_direction'],
        ];
        if ($financial) {
            $renewal += [
                'outstanding' => $record['outstanding'] ?? '0.00',
                'overdue' => $record['overdue'] ?? '0.00',
                'bounced_cheque_count' => $record['bounced_cheque_count'] ?? 0,
            ];
        }

        return $renewal;
    }

    private function filter(array $records, array $filters, bool $financial): array
    {
        if (! $financial && (isset($filters['financial_state']) || isset($filters['overdue_gt']) || isset($filters['outstanding_gt']))) {
            return [];
        }

        return array_values(array_filter($records, function (array $record) use ($filters): bool {
            $outstanding = (float) ($record['outstanding'] ?? 0);
            $overdue = (float) ($record['overdue'] ?? 0);

            return (! isset($filters['financial_state']) || match ($filters['financial_state']) {
                'overdue' => $overdue > 0,
                'outstanding' => $outstanding > 0,
                'no_outstanding' => $outstanding <= 0,
                default => true,
            })
                && (! isset($filters['overdue_gt']) || $overdue > (float) $filters['overdue_gt'])
                && (! isset($filters['overdue_lt']) || $overdue < (float) $filters['overdue_lt'])
                && (! isset($filters['outstanding_gt']) || $outstanding > (float) $filters['outstanding_gt'])
                && (! isset($filters['outstanding_lt']) || $outstanding < (float) $filters['outstanding_lt'])
                && (! ($filters['occupied_properties_only'] ?? false) || ($record['portfolio_impact']['occupied_property_count'] ?? 0) > 0)
                && (! ($filters['coverage_issue'] ?? false) || count(array_intersect($record['renewal_signals'], ['RENEWAL_OWNER_COVERAGE_ENDING', 'RENEWAL_OWNER_COVERAGE_MISMATCH'])) > 0);
        }));
    }

    private function sortKey(array $record): array
    {
        $priority = 9;
        foreach (['RENEWAL_OVERDUE', 'RENEWAL_OWNER_COVERAGE_MISMATCH', 'RENEWAL_WITH_BOUNCED_CHEQUE', 'RENEWAL_WITH_OVERDUE', 'RENEWAL_WITH_OUTSTANDING'] as $index => $signal) {
            if (in_array($signal, $record['renewal_signals'], true)) {
                $priority = min($priority, $index);
            }
        }

        return [$priority, $record['days_until_expiry'], $record['agreement_no']];
    }

    private function defaultRange(): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();

        return ['from' => $today, 'to' => CarbonImmutable::parse($today)->addDays(self::DEFAULT_HORIZON_DAYS)->toDateString()];
    }

    private function suggestions(bool $financial): array
    {
        $suggestions = ['Show tenant renewals only.', 'Show agreements expiring within 30 days.', 'Tell me about the first agreement.'];
        if ($financial) {
            $suggestions[] = 'Show renewals with overdue balances.';
            $suggestions[] = 'Show renewal candidates with no outstanding balance.';
        }

        return $suggestions;
    }
}
