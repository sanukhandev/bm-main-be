<?php

namespace App\Services\Zaakiy\Skills;

use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Services\Zaakiy\ZaakiyReadSkill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CollectionsHealthSkill implements ZaakiyReadSkill
{
    private const COLLECTION_INTENTS = ['collections_health', 'collections_summary', 'outstanding_receivables', 'overdue_receivables', 'upcoming_collections', 'collection_cheques'];

    public function supports(IntentFrame $intent): bool
    {
        return in_array($intent->intent, self::COLLECTION_INTENTS, true) || in_array('collections_health', $intent->modules, true);
    }

    public function execute(ZaakiyExecutionContext $context): ZaakiySkillResult
    {
        if (! $context->can('accounts.view')) {
            return new ZaakiySkillResult(
                intent: $context->intent->intent,
                subject: 'Tenant collections',
                warnings: [['code' => 'FINANCIAL_DATA_RESTRICTED', 'message' => 'Collections information requires accounts.view.']],
                meta: ['result_type' => 'collections_health', 'branch_scoped' => true, 'financial_included' => false],
            );
        }

        $intent = $context->intent->intent;
        $range = $this->range($context, $intent);
        $records = [];
        $sources = [];
        $metrics = [];
        $breakdowns = [];

        if (in_array($intent, ['collections_health', 'collections_summary'], true)) {
            $metrics += $this->collectionMetrics($context->branchId(), $range, $records, $sources);
            $breakdowns['aging'] = $this->aging($context->branchId());
            $breakdowns['cheque_status'] = $this->chequeBreakdown($context->branchId());
        } elseif ($intent === 'outstanding_receivables') {
            $metrics += $this->receivableMetrics($context->branchId(), false);
            $this->rankTenants($context->branchId(), $context->intent->filters, false, $records, $sources);
            $breakdowns['aging'] = $this->aging($context->branchId());
        } elseif ($intent === 'overdue_receivables') {
            $metrics += $this->receivableMetrics($context->branchId(), true);
            $this->rankTenants($context->branchId(), $context->intent->filters, true, $records, $sources);
            $breakdowns['aging'] = $this->aging($context->branchId());
        } elseif ($intent === 'upcoming_collections') {
            $metrics = $this->upcoming($context->branchId(), $range, $records, $sources);
        } else {
            $metrics = $this->cheques($context->branchId(), $range, $records, $sources);
            $breakdowns['cheque_status'] = $this->chequeBreakdown($context->branchId(), $range);
        }

        return new ZaakiySkillResult(
            intent: $intent,
            subject: 'Tenant collections',
            summaryMetrics: $metrics,
            breakdowns: $breakdowns,
            records: array_slice($records, 0, 20),
            sources: array_values(array_slice($this->uniqueSources($sources), 0, 50)),
            navigation: [['label' => 'View inward payments', 'route' => '/app/accounts/inward', 'query' => []]],
            suggestedFollowups: $this->suggestions($intent, $metrics),
            timeRange: $range,
            meta: ['result_type' => 'collections_health', 'branch_scoped' => true, 'financial_included' => true, 'direction' => 'inward', 'outstanding_definition' => 'remaining unpaid tenant agreement installment balances', 'pending_cheque_definition' => 'received or deposited posted inward tenant payments', 'record_count' => min(20, count($records))],
        );
    }

    /** @return array<int, array{dimension:string,key:string,label:string,current:int|float,comparison:int|float,references:array}> */
    public function explanationDrivers(int $branchId, array $currentRange, array $comparisonRange): array
    {
        $current = $this->tenantCollectionTotals($branchId, $currentRange);
        $comparison = $this->tenantCollectionTotals($branchId, $comparisonRange);
        $keys = array_unique([...array_keys($current), ...array_keys($comparison)]);
        $drivers = [];
        foreach ($keys as $key) {
            $row = $current[$key] ?? $comparison[$key];
            $drivers[] = [
                'dimension' => 'tenant',
                'key' => (string) $key,
                'label' => (string) $row->display_name,
                'current' => (float) ($current[$key]->amount ?? 0),
                'comparison' => (float) ($comparison[$key]->amount ?? 0),
                'references' => [['type' => 'tenant', 'id' => (int) $key, 'label' => $row->customer_code]],
            ];
        }

        return $drivers;
    }

    private function tenantCollectionTotals(int $branchId, array $range): array
    {
        return $this->paymentQuery($branchId)
            ->join('customers', 'customers.id', '=', 'transactions.party_customer_id')
            ->whereBetween('transactions.transaction_date', [$range['from'], $range['to']])
            ->selectRaw('customers.id as tenant_id, customers.customer_code, customers.display_name, COALESCE(SUM(transactions.amount), 0) as amount')
            ->groupBy('customers.id', 'customers.customer_code', 'customers.display_name')
            ->get()
            ->keyBy('tenant_id')
            ->all();
    }

    private function collectionMetrics(int $branchId, array $range, array &$records, array &$sources): array
    {
        $payments = $this->paymentQuery($branchId)->whereBetween('transactions.transaction_date', [$range['from'], $range['to']]);
        $totals = (clone $payments)->selectRaw('COUNT(transactions.id) as payment_count, COALESCE(SUM(transactions.amount), 0) as collected_amount, COUNT(DISTINCT transactions.party_customer_id) as unique_tenant_count')->first();
        foreach ((clone $payments)->join('customers', 'customers.id', '=', 'transactions.party_customer_id')->orderByDesc('transactions.transaction_date')->limit(10)->get(['transactions.id', 'transactions.document_no', 'transactions.transaction_date', 'transactions.amount', 'transactions.payment_mode', 'customers.id as tenant_id', 'customers.customer_code', 'customers.display_name']) as $payment) {
            $records[] = ['type' => 'recent_collection', 'id' => $payment->id, 'document_no' => $payment->document_no, 'transaction_date' => $payment->transaction_date, 'amount' => $payment->amount, 'payment_mode' => $payment->payment_mode, 'tenant' => ['id' => $payment->tenant_id, 'customer_code' => $payment->customer_code, 'display_name' => $payment->display_name]];
            $sources[] = $this->source('payment', $payment->id, $payment->document_no);
            $sources[] = $this->source('customer', $payment->tenant_id, $payment->customer_code);
        }

        return [
            'collected_amount' => number_format((float) $totals->collected_amount, 2, '.', ''),
            'payment_count' => (int) $totals->payment_count,
            'unique_tenant_count' => (int) $totals->unique_tenant_count,
            ...$this->receivableMetrics($branchId, false),
            'pending_cheque_count' => $this->chequeCount($branchId, ['received', 'deposited']),
            'bounced_cheque_count' => $this->chequeCount($branchId, ['bounced']),
        ];
    }

    private function receivableMetrics(int $branchId, bool $overdue): array
    {
        $query = $this->installmentQuery($branchId)->whereColumn('installments.paid_amount', '<', 'installments.amount');
        if ($overdue) {
            $query->whereDate('installments.due_date', '<', now()->toDateString());
        }
        $totals = (clone $query)->select([])->selectRaw('COALESCE(SUM(installments.amount - installments.paid_amount), 0) as total, COUNT(*) as installment_count, COUNT(DISTINCT agreements.tenant_customer_id) as tenant_count, COUNT(DISTINCT agreements.id) as agreement_count')->first();

        return [
            $overdue ? 'overdue_total' : 'outstanding_total' => number_format((float) $totals->total, 2, '.', ''),
            $overdue ? 'tenant_count_with_overdue' : 'tenant_count_with_outstanding' => (int) $totals->tenant_count,
            'agreement_count' => (int) $totals->agreement_count,
            $overdue ? 'overdue_installment_count' : 'unpaid_installment_count' => (int) $totals->installment_count,
        ];
    }

    private function rankTenants(int $branchId, array $filters, bool $overdue, array &$records, array &$sources): void
    {
        $query = $this->installmentQuery($branchId)->whereColumn('installments.paid_amount', '<', 'installments.amount');
        $query->when($overdue, fn ($builder) => $builder->whereDate('installments.due_date', '<', now()->toDateString()));
        $query->selectRaw('agreements.tenant_customer_id as tenant_id, customers.customer_code, customers.display_name, SUM(installments.amount - installments.paid_amount) as balance, COUNT(DISTINCT agreements.id) as agreement_count');
        if ($overdue) {
            $query->selectRaw('SUM(CASE WHEN installments.due_date < ? THEN installments.amount - installments.paid_amount ELSE 0 END) as overdue', [now()->toDateString()]);
        } else {
            $query->selectRaw('SUM(CASE WHEN installments.due_date < ? THEN installments.amount - installments.paid_amount ELSE 0 END) as overdue', [now()->toDateString()]);
        }
        $query->groupBy('agreements.tenant_customer_id', 'customers.customer_code', 'customers.display_name')->orderByDesc('balance')->limit(10);
        if (isset($filters['outstanding_gt'])) {
            $query->havingRaw('SUM(installments.amount - installments.paid_amount) > '.(float) $filters['outstanding_gt']);
        }
        if (isset($filters['overdue_gt'])) {
            $query->havingRaw("SUM(CASE WHEN installments.due_date < '".now()->toDateString()."' THEN installments.amount - installments.paid_amount ELSE 0 END) > ".(float) $filters['overdue_gt']);
        }
        foreach ($query->get() as $tenant) {
            $records[] = ['type' => $overdue ? 'tenant_overdue_summary' : 'tenant_outstanding_summary', 'id' => $tenant->tenant_id, 'customer_code' => $tenant->customer_code, 'display_name' => $tenant->display_name, 'outstanding' => number_format((float) $tenant->balance, 2, '.', ''), 'overdue' => number_format((float) $tenant->overdue, 2, '.', ''), 'agreement_count' => (int) $tenant->agreement_count];
            $sources[] = $this->source('tenant', $tenant->tenant_id, $tenant->customer_code);
            $sources[] = $this->source('customer', $tenant->tenant_id, $tenant->customer_code);
        }
    }

    private function upcoming(int $branchId, array $range, array &$records, array &$sources): array
    {
        $baseQuery = $this->installmentQuery($branchId)->whereColumn('installments.paid_amount', '<', 'installments.amount')->whereBetween('installments.due_date', [$range['from'], $range['to']]);
        $totals = (clone $baseQuery)->select([])->selectRaw('COALESCE(SUM(installments.amount - installments.paid_amount), 0) as due_amount, COUNT(*) as installment_count, COUNT(DISTINCT agreements.tenant_customer_id) as tenant_count')->first();
        $query = (clone $baseQuery)->orderBy('installments.due_date')->limit(20);
        foreach ($query->get() as $line) {
            $records[] = ['type' => 'due_installment', 'id' => $line->installment_id, 'tenant' => ['id' => $line->tenant_id, 'customer_code' => $line->customer_code, 'display_name' => $line->display_name], 'tenant_agreement' => ['id' => $line->agreement_id, 'agreement_no' => $line->agreement_no], 'property' => ['property_code' => $line->property_code], 'installment_no' => $line->installment_no, 'due_date' => $line->due_date, 'amount' => $line->amount, 'paid_amount' => $line->paid_amount, 'balance' => number_format((float) $line->amount - (float) $line->paid_amount, 2, '.', ''), 'payment_mode' => $line->payment_mode];
            $sources[] = $this->source('installment', $line->installment_id, 'Installment '.$line->installment_no);
            $sources[] = $this->source('tenant_agreement', $line->agreement_id, $line->agreement_no);
            $sources[] = $this->source('tenant', $line->tenant_id, $line->customer_code);
        }

        return ['scheduled_due' => number_format((float) $totals->due_amount, 2, '.', ''), 'installment_count' => (int) $totals->installment_count, 'tenant_count' => (int) $totals->tenant_count];
    }

    private function cheques(int $branchId, array $range, array &$records, array &$sources): array
    {
        $query = $this->paymentQuery($branchId)->where('transactions.payment_mode', 'cheque')->whereBetween('transactions.cheque_date', [$range['from'], $range['to']])->orderBy('transactions.cheque_date')->limit(20);
        $counts = (clone $query)->selectRaw('transactions.cheque_status, COUNT(transactions.id) as count, COALESCE(SUM(transactions.amount), 0) as amount')->groupBy('transactions.cheque_status')->get()->keyBy('cheque_status');
        foreach ($query->get(['transactions.id', 'transactions.document_no', 'transactions.cheque_date', 'transactions.cheque_status', 'transactions.amount', 'transactions.party_customer_id']) as $cheque) {
            $records[] = ['type' => 'cheque', 'id' => $cheque->id, 'document_no' => $cheque->document_no, 'cheque_date' => $cheque->cheque_date, 'cheque_status' => $cheque->cheque_status, 'amount' => $cheque->amount, 'tenant_id' => $cheque->party_customer_id];
            $sources[] = $this->source('payment', $cheque->id, $cheque->document_no);
        }

        return collect(['received', 'deposited', 'cleared', 'bounced', 'cancelled'])->mapWithKeys(fn (string $status): array => [$status.'_cheque_count' => (int) ($counts[$status]->count ?? 0)])->all();
    }

    private function aging(int $branchId): array
    {
        $base = fn () => $this->installmentQuery($branchId)->whereColumn('installments.paid_amount', '<', 'installments.amount');
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $ranges = [
            'current' => [$today->toDateString(), null],
            '1-30' => [$today->subDays(30)->toDateString(), $today->subDay()->toDateString()],
            '31-60' => [$today->subDays(60)->toDateString(), $today->subDays(31)->toDateString()],
            '61-90' => [$today->subDays(90)->toDateString(), $today->subDays(61)->toDateString()],
            '90+' => [null, $today->subDays(91)->toDateString()],
        ];
        $result = [];
        foreach ($ranges as $bucket => [$from, $to]) {
            $query = $base();
            if ($from !== null && $bucket === 'current') {
                $query->whereDate('installments.due_date', '>=', $from);
            } elseif ($from !== null) {
                $query->whereBetween('installments.due_date', [$from, $to]);
            } else {
                $query->whereDate('installments.due_date', '<=', $to);
            }
            $totals = $query->select([])->selectRaw('COALESCE(SUM(installments.amount), 0) as scheduled, COALESCE(SUM(installments.paid_amount), 0) as paid')->first();
            $result[] = ['bucket' => $bucket, 'amount' => number_format((float) $totals->scheduled - (float) $totals->paid, 2, '.', '')];
        }

        return $result;
    }

    private function chequeBreakdown(int $branchId, ?array $range = null): array
    {
        $query = $this->paymentQuery($branchId)->where('transactions.payment_mode', 'cheque');
        if ($range) {
            $query->whereBetween('transactions.cheque_date', [$range['from'], $range['to']]);
        }

        return (clone $query)->selectRaw('transactions.cheque_status as status, COUNT(DISTINCT transactions.id) as count')->groupBy('transactions.cheque_status')->get()->map(fn ($row): array => ['status' => $row->status, 'count' => (int) $row->count])->values()->all();
    }

    private function chequeCount(int $branchId, array $statuses): int
    {
        return (int) $this->paymentQuery($branchId)->where('transactions.payment_mode', 'cheque')->whereIn('transactions.cheque_status', $statuses)->distinct('transactions.id')->count('transactions.id');
    }

    private function paymentQuery(int $branchId)
    {
        return DB::table('account_transactions as transactions')->where('transactions.branch_id', $branchId)->where('transactions.direction', 'inward')->where('transactions.status', 'posted')->whereExists(function ($query) use ($branchId): void {
            $query->selectRaw('1')->from('account_transaction_allocations as allocations')->join('tenant_agreement_installments as installments', 'installments.id', '=', 'allocations.tenant_agreement_installment_id')->whereColumn('allocations.account_transaction_id', 'transactions.id')->where('allocations.branch_id', $branchId)->where('installments.branch_id', $branchId);
        });
    }

    private function installmentQuery(int $branchId)
    {
        return DB::table('tenant_agreement_installments as installments')->join('tenant_agreements as agreements', 'agreements.id', '=', 'installments.tenant_agreement_id')->join('customers', 'customers.id', '=', 'agreements.tenant_customer_id')->where('installments.branch_id', $branchId)->where('agreements.branch_id', $branchId)->where('customers.branch_id', $branchId)->whereNull('customers.deleted_at')->whereNotIn('agreements.status', ['draft', 'cancelled', 'terminated'])->select(['installments.id as installment_id', 'installments.installment_no', 'installments.due_date', 'installments.amount', 'installments.paid_amount', 'installments.payment_mode', 'agreements.id as agreement_id', 'agreements.agreement_no', 'agreements.tenant_customer_id as tenant_id', 'customers.customer_code', 'customers.display_name'])->selectSub(function ($query) use ($branchId) {
            return $query->from('tenant_agreement_properties as links')->join('properties', 'properties.id', '=', 'links.property_id')->whereColumn('links.tenant_agreement_id', 'installments.tenant_agreement_id')->where('links.branch_id', $branchId)->select('properties.property_code')->limit(1);
        }, 'property_code');
    }

    private function range(ZaakiyExecutionContext $context, string $intent): array
    {
        if ($context->intent->timeRange) {
            return $context->intent->timeRange;
        }
        $now = CarbonImmutable::now(config('app.timezone'));
        if ($intent === 'upcoming_collections') {
            return ['from' => $now->toDateString(), 'to' => $now->addDays(7)->toDateString()];
        }

        return ['from' => $now->startOfMonth()->toDateString(), 'to' => $now->endOfMonth()->toDateString()];
    }

    private function suggestions(string $intent, array $metrics): array
    {
        return array_slice(match ($intent) {
            'overdue_receivables' => ['Show balances above AED 10,000.', 'Show the related agreements.'],
            'outstanding_receivables' => ['Show overdue tenants.', 'What is due this week?'],
            'upcoming_collections' => ['Show overdue tenants.', 'Which cheques are pending?'],
            'collection_cheques' => ['Show overdue tenants.', 'What is due this week?'],
            'collections_summary', 'collections_health' => ['Show overdue tenants.', 'Who owes us the most?', 'What is due this week?'],
            default => [],
        }, 0, 5);
    }

    private function source(string $type, ?int $id, ?string $label): ?array
    {
        return $id && $label ? ['type' => $type, 'id' => $id, 'label' => $label] : null;
    }

    private function uniqueSources(array $sources): array
    {
        $unique = [];
        foreach ($sources as $source) {
            if ($source) {
                $unique[$source['type'].':'.$source['id']] = $source;
            }
        }

        return array_values($unique);
    }
}
