<?php

namespace App\Services\Zaakiy\Skills;

use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Services\Zaakiy\ZaakiyReadSkill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AgreementRiskSkill implements ZaakiyReadSkill
{
    private const ACTIVE_STATUSES = ['approved', 'commenced', 'on_hold'];

    private const SIGNAL_ORDER = [
        'OWNER_COVERAGE_BEFORE_TENANT_END',
        'EXPIRED_ACTIVE_STATUS',
        'BOUNCED_CHEQUE',
        'EXPIRING_WITH_OVERDUE',
        'OVERDUE_BALANCE',
        'EXPIRING_WITH_OUTSTANDING',
        'EXPIRING_SOON',
        'OWNER_COVERAGE_ENDING',
        'OUTSTANDING_BALANCE',
    ];

    public function supports(IntentFrame $intent): bool
    {
        return in_array($intent->intent, ['agreement_risk', 'agreement_attention', 'agreement_expiry', 'agreement_financial_attention'], true)
            || in_array('agreement_risk', $intent->modules, true);
    }

    public function execute(ZaakiyExecutionContext $context): ZaakiySkillResult
    {
        $financial = $context->can('accounts.view');
        $range = $this->range($context);
        $type = $context->intent->filters['agreement_type'] ?? null;
        $rows = [];

        if ($type !== 'owner') {
            $rows = array_merge($rows, $this->agreements($context->branchId(), 'tenant', $range, $financial));
        }
        if ($type !== 'tenant') {
            $rows = array_merge($rows, $this->agreements($context->branchId(), 'owner', $range, $financial));
        }

        $rows = array_values(array_filter(array_map(fn ($row): ?array => $this->flag($row, $range, $financial), $rows)));
        usort($rows, function (array $left, array $right): int {
            $priority = fn (array $row): int => min(array_map(fn (string $code): int => array_search($code, self::SIGNAL_ORDER, true), $row['signals']));

            return [$priority($left), $left['end_date'] ?? '9999-12-31', -((float) ($left['overdue'] ?? 0)), $left['agreement_no']]
                <=> [$priority($right), $right['end_date'] ?? '9999-12-31', -((float) ($right['overdue'] ?? 0)), $right['agreement_no']];
        });
        $rows = array_slice($rows, 0, 25);

        $signalCounts = [];
        $totalOutstanding = 0.0;
        $totalOverdue = 0.0;
        foreach ($rows as $row) {
            $totalOutstanding += (float) ($row['outstanding'] ?? 0);
            $totalOverdue += (float) ($row['overdue'] ?? 0);
            foreach ($row['signals'] as $signal) {
                $signalCounts[$signal] = ($signalCounts[$signal] ?? 0) + 1;
            }
        }

        $metrics = [
            'agreement_count_needing_attention' => count($rows),
            'expiring_soon_count' => $signalCounts['EXPIRING_SOON'] ?? 0,
            'overdue_balance_count' => $signalCounts['OVERDUE_BALANCE'] ?? 0,
            'bounced_cheque_agreement_count' => $signalCounts['BOUNCED_CHEQUE'] ?? 0,
            'coverage_mismatch_count' => $signalCounts['OWNER_COVERAGE_BEFORE_TENANT_END'] ?? 0,
        ];
        if ($financial) {
            $metrics['total_outstanding_amount'] = number_format($totalOutstanding, 2, '.', '');
            $metrics['total_overdue_amount'] = number_format($totalOverdue, 2, '.', '');
        }

        $breakdowns = ['attention_signals' => collect($signalCounts)->map(fn (int $count, string $code): array => ['code' => $code, 'agreement_count' => $count])->values()->all()];
        $sources = [];
        $records = [];
        foreach ($rows as $row) {
            $records[] = $row;
            $sources[] = $this->source($row['type'], $row['id'], $row['agreement_no']);
            $sources[] = $this->source('customer', $row['party_id'], $row['party_code']);
        }

        $warnings = $financial ? [] : [['code' => 'FINANCIAL_DATA_RESTRICTED', 'message' => 'Financial attention signals require accounts.view.']];
        $navigation = [['label' => 'View agreement attention', 'route' => '/app/reports/agreement-expiry', 'query' => []]];
        foreach (array_slice($rows, 0, 5) as $row) {
            $navigation[] = ['label' => 'View '.$row['agreement_no'], 'route' => '/app/'.($row['type'] === 'owner_agreement' ? 'owner-agreements' : 'tenant-agreements').'/'.$row['id'], 'query' => []];
        }

        return new ZaakiySkillResult(
            intent: $context->intent->intent,
            subject: 'Agreement attention',
            summaryMetrics: $metrics,
            records: array_map(fn (array $row): array => $this->publicRecord($row, $financial), $records),
            breakdowns: $breakdowns,
            warnings: $warnings,
            sources: array_values(array_filter($sources)),
            navigation: $navigation,
            suggestedFollowups: $this->suggestions($financial),
            timeRange: $range,
            meta: ['result_type' => 'agreement_risk', 'branch_scoped' => true, 'financial_included' => $financial, 'deterministic' => true, 'risk_score' => false, 'record_count' => count($records)],
        );
    }

    private function agreements(int $branchId, string $type, array $range, bool $financial): array
    {
        $table = $type === 'tenant' ? 'tenant_agreements' : 'owner_agreements';
        $foreign = $type === 'tenant' ? 'tenant_agreement_id' : 'owner_agreement_id';
        $installments = $type === 'tenant' ? 'tenant_agreement_installments' : 'owner_agreement_installments';
        $allocationForeign = $type === 'tenant' ? 'tenant_agreement_installment_id' : 'owner_agreement_installment_id';
        $partyForeign = $type === 'tenant' ? 'tenant_customer_id' : 'owner_customer_id';
        $direction = $type === 'tenant' ? 'inward' : 'outward';
        $agreementType = $type.'_agreement';

        $query = DB::table($table.' as agreements')
            ->join('customers', 'customers.id', '=', 'agreements.'.$partyForeign)
            ->where('agreements.branch_id', $branchId)
            ->whereIn('agreements.status', self::ACTIVE_STATUSES)
            ->whereNull('agreements.deleted_at')
            ->whereNull('customers.deleted_at')
            ->where(function ($builder) use ($financial, $installments, $foreign, $allocationForeign, $branchId, $direction, $range, $type): void {
                $builder->whereDate('agreements.end_date', '<=', $range['to']);
                if ($financial) {
                    $builder->orWhereExists(fn ($exists) => $exists->from($installments.' as installments')->whereColumn('installments.'.$foreign, 'agreements.id')->where('installments.branch_id', $branchId)->whereColumn('installments.paid_amount', '<', 'installments.amount'))
                        ->orWhereExists(fn ($exists) => $exists->from('account_transaction_allocations as allocations')->join($installments.' as installments', 'installments.id', '=', 'allocations.'.$allocationForeign)->join('account_transactions as transactions', 'transactions.id', '=', 'allocations.account_transaction_id')->whereColumn('installments.'.$foreign, 'agreements.id')->where('allocations.branch_id', $branchId)->where('installments.branch_id', $branchId)->where('transactions.branch_id', $branchId)->where('transactions.direction', $direction)->where('transactions.status', 'posted')->where('transactions.payment_mode', 'cheque')->where('transactions.cheque_status', 'bounced'));
                }
                if ($type === 'tenant') {
                    $builder->orWhereExists(fn ($exists) => $exists->from('tenant_agreement_properties as links')->join('owner_agreements as owners', function ($join): void {
                        $join->on('owners.id', '=', 'links.source_owner_agreement_id')->on('owners.branch_id', '=', 'links.branch_id');
                    })->whereColumn('links.tenant_agreement_id', 'agreements.id')->where('links.branch_id', $branchId)->whereColumn('owners.end_date', '<', 'agreements.end_date')->whereIn('owners.status', self::ACTIVE_STATUSES));
                }
            })
            ->select(['agreements.id', 'agreements.agreement_no', 'agreements.status', 'agreements.start_date', 'agreements.end_date', 'customers.id as party_id', 'customers.customer_code as party_code', 'customers.display_name as party_name'])
            ->selectSub(fn ($sub) => $sub->from($installments.' as installments')->whereColumn('installments.'.$foreign, 'agreements.id')->where('installments.branch_id', $branchId)->selectRaw('COALESCE(SUM(amount), 0) - COALESCE(SUM(paid_amount), 0)'), 'outstanding')
            ->selectSub(fn ($sub) => $sub->from($installments.' as installments')->whereColumn('installments.'.$foreign, 'agreements.id')->where('installments.branch_id', $branchId)->whereDate('due_date', '<', now()->toDateString())->selectRaw('COALESCE(SUM(amount), 0) - COALESCE(SUM(paid_amount), 0)'), 'overdue')
            ->selectSub(fn ($sub) => $sub->from('account_transaction_allocations as allocations')->join($installments.' as installments', 'installments.id', '=', 'allocations.'.$allocationForeign)->join('account_transactions as transactions', 'transactions.id', '=', 'allocations.account_transaction_id')->whereColumn('installments.'.$foreign, 'agreements.id')->where('allocations.branch_id', $branchId)->where('installments.branch_id', $branchId)->where('transactions.branch_id', $branchId)->where('transactions.direction', $direction)->where('transactions.status', 'posted')->where('transactions.payment_mode', 'cheque')->where('transactions.cheque_status', 'bounced')->selectRaw('COUNT(*)'), 'bounced_cheque_count');

        if ($type === 'tenant') {
            $query->selectSub(fn ($sub) => $sub->from('tenant_agreement_properties as links')->join('properties', 'properties.id', '=', 'links.property_id')->whereColumn('links.tenant_agreement_id', 'agreements.id')->where('links.branch_id', $branchId)->selectRaw("GROUP_CONCAT(properties.property_code, ', ')")->limit(1), 'properties');
            $query->selectSub(fn ($sub) => $sub->from('tenant_agreement_properties as links')->join('owner_agreements as owners', function ($join): void {
                $join->on('owners.id', '=', 'links.source_owner_agreement_id')->on('owners.branch_id', '=', 'links.branch_id');
            })->whereColumn('links.tenant_agreement_id', 'agreements.id')->where('links.branch_id', $branchId)->whereColumn('owners.end_date', '<', 'agreements.end_date')->whereIn('owners.status', self::ACTIVE_STATUSES)->selectRaw('COUNT(*)'), 'coverage_mismatch');
            $query->selectSub(fn ($sub) => $sub->from('tenant_agreement_properties as links')->join('owner_agreements as owners', function ($join): void {
                $join->on('owners.id', '=', 'links.source_owner_agreement_id')->on('owners.branch_id', '=', 'links.branch_id');
            })->whereColumn('links.tenant_agreement_id', 'agreements.id')->where('links.branch_id', $branchId)->whereBetween('owners.end_date', [now()->toDateString(), $range['to']])->whereColumn('owners.end_date', '<', 'agreements.end_date')->whereIn('owners.status', self::ACTIVE_STATUSES)->selectRaw('COUNT(*)'), 'coverage_ending');
        } else {
            $query->selectSub(fn ($sub) => $sub->from('owner_agreement_properties as links')->join('properties', 'properties.id', '=', 'links.property_id')->whereColumn('links.owner_agreement_id', 'agreements.id')->where('links.branch_id', $branchId)->selectRaw("GROUP_CONCAT(properties.property_code, ', ')")->limit(1), 'properties');
            $query->selectRaw('0 as coverage_mismatch, 0 as coverage_ending');
        }

        return $query->get()->map(function ($row) use ($agreementType, $type): array {
            return ['type' => $agreementType, 'id' => (int) $row->id, 'agreement_no' => $row->agreement_no, 'status' => $row->status, 'start_date' => $row->start_date, 'end_date' => $row->end_date, 'party_id' => (int) $row->party_id, 'party_code' => $row->party_code, 'party_name' => $row->party_name, 'properties' => $row->properties, 'outstanding' => (float) $row->outstanding, 'overdue' => (float) $row->overdue, 'bounced_cheque_count' => (int) $row->bounced_cheque_count, 'coverage_mismatch' => (int) $row->coverage_mismatch > 0, 'coverage_ending' => (int) $row->coverage_ending > 0, 'direction' => $type === 'tenant' ? 'inward' : 'outward'];
        })->all();
    }

    private function flag(array $row, array $range, bool $financial): ?array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $end = CarbonImmutable::parse($row['end_date'], config('app.timezone'))->startOfDay();
        $days = $today->diffInDays($end, false);
        $signals = [];
        if ($days < 0) {
            $signals[] = 'EXPIRED_ACTIVE_STATUS';
        } elseif ($end->toDateString() >= $range['from'] && $end->toDateString() <= $range['to']) {
            $signals[] = 'EXPIRING_SOON';
        }
        if ($financial && $row['outstanding'] > 0) {
            $signals[] = 'OUTSTANDING_BALANCE';
        }
        if ($financial && $row['overdue'] > 0) {
            $signals[] = 'OVERDUE_BALANCE';
        }
        if ($financial && $row['bounced_cheque_count'] > 0) {
            $signals[] = 'BOUNCED_CHEQUE';
        }
        if ($row['coverage_mismatch']) {
            $signals[] = 'OWNER_COVERAGE_BEFORE_TENANT_END';
        } elseif ($row['coverage_ending']) {
            $signals[] = 'OWNER_COVERAGE_ENDING';
        }
        if ($financial && in_array('EXPIRING_SOON', $signals, true) && $row['outstanding'] > 0) {
            $signals[] = 'EXPIRING_WITH_OUTSTANDING';
        }
        if ($financial && in_array('EXPIRING_SOON', $signals, true) && $row['overdue'] > 0) {
            $signals[] = 'EXPIRING_WITH_OVERDUE';
        }

        return $signals === [] ? null : [...$row, 'days_until_expiry' => $days, 'signals' => array_values(array_unique($signals))];
    }

    private function publicRecord(array $row, bool $financial): array
    {
        $record = ['type' => $row['type'], 'id' => $row['id'], 'agreement_no' => $row['agreement_no'], 'party' => ['id' => $row['party_id'], 'customer_code' => $row['party_code'], 'display_name' => $row['party_name']], 'properties' => $row['properties'], 'status' => $row['status'], 'start_date' => $row['start_date'], 'end_date' => $row['end_date'], 'days_until_expiry' => $row['days_until_expiry'], 'signals' => $row['signals'], 'financial_direction' => $row['direction']];
        if ($financial) {
            $record += ['outstanding' => number_format($row['outstanding'], 2, '.', ''), 'overdue' => number_format($row['overdue'], 2, '.', ''), 'bounced_cheque_count' => $row['bounced_cheque_count']];
        }

        return $record;
    }

    private function range(ZaakiyExecutionContext $context): array
    {
        $range = $context->intent->timeRange;
        if ($range) {
            return $range;
        }

        $now = CarbonImmutable::now(config('app.timezone'));

        return ['from' => $now->toDateString(), 'to' => $now->addDays(30)->toDateString()];
    }

    private function source(string $type, ?int $id, ?string $label): ?array
    {
        return $id && $label ? ['type' => $type, 'id' => $id, 'label' => $label] : null;
    }

    private function suggestions(bool $financial): array
    {
        $suggestions = ['Show only expiring agreements.', 'Show tenant agreements only.', 'Tell me about the first agreement.'];
        if ($financial) {
            $suggestions[] = 'Show agreements with overdue balances.';
            $suggestions[] = 'Which agreements have bounced cheques?';
        }

        return array_slice($suggestions, 0, 5);
    }
}
