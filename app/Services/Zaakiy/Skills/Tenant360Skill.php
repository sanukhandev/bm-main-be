<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\Customer;
use App\Models\TenantAgreement;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\EntityResolver;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Services\Zaakiy\ZaakiyReadSkill;
use Illuminate\Support\Facades\DB;

final class Tenant360Skill implements ZaakiyReadSkill
{
    private const ACTIVE_STATUSES = ['pending_approval', 'approved', 'commenced', 'on_hold'];

    public function __construct(private readonly EntityResolver $entities) {}

    public function supports(IntentFrame $intent): bool
    {
        return $intent->intent === 'tenant_360' || in_array('tenant_360', $intent->modules, true);
    }

    public function execute(ZaakiyExecutionContext $context): ZaakiySkillResult
    {
        $matches = $this->entities->resolveTenants($context->intent->question, $context);
        if (count($matches) !== 1) {
            return $this->unresolved($matches);
        }

        $tenant = $matches[0];
        $branchId = $context->branchId();
        $financial = $context->can('accounts.view');
        $agreements = TenantAgreement::query()->forBranch($branchId)->where('tenant_customer_id', $tenant->id)
            ->whereNotIn('status', ['draft', 'cancelled', 'terminated'])->with('properties')->latest('id')->limit(5)->get();
        $current = $agreements->filter(fn (TenantAgreement $agreement): bool => in_array($agreement->status, self::ACTIVE_STATUSES, true)
            && $agreement->start_date?->toDateString() <= now()->toDateString()
            && $agreement->end_date?->toDateString() >= now()->toDateString())->values();
        $active = $current->isNotEmpty() ? $current : $agreements->filter(fn (TenantAgreement $agreement): bool => in_array($agreement->status, self::ACTIVE_STATUSES, true))->values();
        $agreementIds = TenantAgreement::query()->forBranch($branchId)->where('tenant_customer_id', $tenant->id)->whereNotIn('status', ['draft', 'cancelled', 'terminated'])->pluck('id')->all();
        $propertyIds = $active->flatMap->properties->pluck('id')->unique()->values()->all();

        $records = [$this->tenantRecord($tenant)];
        $sources = [$this->source('customer', $tenant->id, $tenant->customer_code)];
        $navigation = [['label' => 'View tenant', 'route' => '/app/customers/'.$tenant->id, 'query' => []]];
        $suggestions = [];
        foreach ($active as $agreement) {
            $records[] = $this->agreementRecord($agreement);
            $sources[] = $this->source('tenant_agreement', $agreement->id, $agreement->agreement_no);
            $navigation[] = ['label' => 'View tenant agreement', 'route' => '/app/tenant-agreements/'.$agreement->id, 'query' => []];
            $suggestions[] = 'When does the agreement expire?';
            foreach ($agreement->properties as $property) {
                $records[] = $this->propertyRecord($property);
                $sources[] = $this->source('property', $property->id, $property->property_code);
                $navigation[] = ['label' => 'View rented property', 'route' => '/app/properties/'.$property->id, 'query' => []];
            }
        }
        foreach ($agreements->reject(fn (TenantAgreement $agreement): bool => $active->contains('id', $agreement->id))->take(5) as $agreement) {
            $records[] = $this->agreementRecord($agreement, 'recent_agreement');
            $sources[] = $this->source('tenant_agreement', $agreement->id, $agreement->agreement_no);
        }

        $metrics = ['active_agreement_count' => $active->count()];
        if ($propertyIds !== []) {
            $metrics['current_property_open_work_orders'] = DB::table('work_orders')->where('branch_id', $branchId)->whereIn('property_id', $propertyIds)->whereNotIn('status', ['completed', 'cancelled'])->count();
        }
        $warnings = [];
        if ($financial && $agreementIds !== []) {
            $metrics += $this->financialMetrics($branchId, $agreementIds, $records, $sources);
            $navigation[] = ['label' => 'View tenant outstanding', 'route' => '/app/reports/tenant-outstanding', 'query' => ['customer_id' => $tenant->id]];
            $suggestions[] = 'Show outstanding payments.';
            $suggestions[] = 'Show recent payments.';
            if (($metrics['pending_cheque_count'] ?? 0) > 0) {
                $suggestions[] = 'Any pending cheques?';
            }
        } elseif (! $financial) {
            $warnings[] = ['code' => 'FINANCIAL_DATA_RESTRICTED', 'message' => 'Financial information requires accounts.view.'];
        }
        if ($active->isNotEmpty()) {
            $suggestions[] = 'What property are they renting?';
        }

        return new ZaakiySkillResult(
            intent: 'tenant_360',
            subject: (string) $tenant->display_name,
            summaryMetrics: $metrics,
            records: $records,
            warnings: $warnings,
            sources: array_values(array_filter($sources, fn (?array $source): bool => $source !== null)),
            navigation: $this->uniqueNavigation($navigation),
            suggestedFollowups: array_values(array_unique(array_slice($suggestions, 0, 5))),
            meta: ['result_type' => 'tenant_360', 'branch_scoped' => true, 'financial_included' => $financial, 'record_count' => count($records)],
        );
    }

    private function unresolved(array $matches): ZaakiySkillResult
    {
        return new ZaakiySkillResult(
            intent: 'tenant_360',
            subject: 'Tenant summary',
            records: array_map(fn (Customer $tenant): array => $this->tenantRecord($tenant), array_slice($matches, 0, 5)),
            warnings: [[
                'code' => $matches === [] ? 'TENANT_NOT_FOUND' : 'TENANT_AMBIGUOUS',
                'message' => $matches === [] ? 'No authorized tenant matched the request.' : 'More than one authorized tenant matched; no tenant was selected.',
            ]],
            meta: ['result_type' => 'tenant_360', 'branch_scoped' => true, 'record_count' => count($matches)],
        );
    }

    private function financialMetrics(int $branchId, array $agreementIds, array &$records, array &$sources): array
    {
        $installments = DB::table('tenant_agreement_installments')->where('branch_id', $branchId)->whereIn('tenant_agreement_id', $agreementIds)->whereColumn('paid_amount', '<', 'amount');
        $outstanding = (float) (clone $installments)->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total');
        $overdueQuery = (clone $installments)->whereDate('due_date', '<', now()->toDateString());
        $overdue = (float) (clone $overdueQuery)->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total');
        $next = (clone $installments)->orderBy('due_date')->first(['id', 'installment_no', 'due_date', 'amount', 'paid_amount']);
        if ($next) {
            $records[] = ['type' => 'next_installment', 'id' => $next->id, 'installment_no' => $next->installment_no, 'due_date' => $next->due_date, 'amount' => $next->amount, 'paid_amount' => $next->paid_amount, 'balance' => number_format((float) $next->amount - (float) $next->paid_amount, 2, '.', '')];
            $sources[] = $this->source('installment', $next->id, 'Installment '.$next->installment_no);
        }
        $paymentQuery = $this->paymentQuery($branchId, $agreementIds);
        $last = (clone $paymentQuery)->orderByDesc('transactions.transaction_date')->orderByDesc('transactions.id')->first();
        if ($last) {
            $records[] = $this->paymentRecord($last, 'last_payment');
            $sources[] = $this->source('payment', $last->id, $last->document_no);
        }
        $recent = (clone $paymentQuery)->orderByDesc('transactions.transaction_date')->orderByDesc('transactions.id')->limit(3)->get();
        foreach ($recent as $payment) {
            if (! $last || (int) $payment->id !== (int) $last->id) {
                $records[] = $this->paymentRecord($payment, 'recent_payment');
                $sources[] = $this->source('payment', $payment->id, $payment->document_no);
            }
        }
        $cheques = (clone $paymentQuery)->where('transactions.payment_mode', 'cheque');
        $chequeCounts = $cheques->select('transactions.cheque_status', DB::raw('COUNT(DISTINCT transactions.id) as count'))->groupBy('transactions.cheque_status')->pluck('count', 'cheque_status');

        return [
            'outstanding_receivable' => number_format($outstanding, 2, '.', ''),
            'overdue_receivable' => number_format($overdue, 2, '.', ''),
            'unpaid_installment_count' => (clone $installments)->count(),
            'overdue_installment_count' => (clone $overdueQuery)->count(),
            'pending_cheque_count' => (int) ($chequeCounts['received'] ?? 0) + (int) ($chequeCounts['deposited'] ?? 0),
            'deposited_cheque_count' => (int) ($chequeCounts['deposited'] ?? 0),
            'bounced_cheque_count' => (int) ($chequeCounts['bounced'] ?? 0),
        ];
    }

    private function paymentQuery(int $branchId, array $agreementIds)
    {
        return DB::table('account_transactions as transactions')
            ->join('account_transaction_allocations as allocations', 'allocations.account_transaction_id', '=', 'transactions.id')
            ->join('tenant_agreement_installments as installments', 'installments.id', '=', 'allocations.tenant_agreement_installment_id')
            ->where('transactions.branch_id', $branchId)->where('transactions.status', 'posted')
            ->where('allocations.branch_id', $branchId)->where('installments.branch_id', $branchId)
            ->whereIn('installments.tenant_agreement_id', $agreementIds)
            ->whereNotNull('allocations.tenant_agreement_installment_id')
            ->select(['transactions.id', 'transactions.document_no', 'transactions.transaction_date', 'transactions.direction', 'transactions.payment_mode', 'transactions.amount', 'transactions.cheque_status'])
            ->distinct();
    }

    private function tenantRecord(Customer $tenant): array
    {
        return array_filter(['type' => 'tenant', 'id' => $tenant->id, 'customer_code' => $tenant->customer_code, 'display_name' => $tenant->display_name, 'legal_name' => $tenant->legal_name, 'customer_type' => $tenant->customer_type, 'status' => $tenant->status], static fn ($value) => $value !== null && $value !== '');
    }

    private function agreementRecord(TenantAgreement $agreement, string $type = 'active_tenant_agreement'): array
    {
        return ['type' => $type, 'id' => $agreement->id, 'agreement_no' => $agreement->agreement_no, 'start_date' => $agreement->start_date?->format('Y-m-d'), 'end_date' => $agreement->end_date?->format('Y-m-d'), 'status' => $agreement->status, 'properties' => $agreement->properties->map(fn ($property) => $this->propertyRecord($property))->values()->all()];
    }

    private function propertyRecord($property): array
    {
        return array_filter(['type' => 'property', 'id' => $property->id, 'property_code' => $property->property_code, 'unit_number' => $property->unit_number, 'name' => $property->name, 'property_type' => $property->property_type instanceof \BackedEnum ? $property->property_type->value : $property->property_type, 'status' => $property->status, 'city' => $property->city, 'state_or_emirate' => $property->state_or_emirate], static fn ($value) => $value !== null && $value !== '');
    }

    private function paymentRecord($payment, string $type): array
    {
        return ['type' => $type, 'id' => $payment->id, 'document_no' => $payment->document_no, 'transaction_date' => $payment->transaction_date, 'direction' => $payment->direction, 'payment_mode' => $payment->payment_mode, 'amount' => $payment->amount, 'cheque_status' => $payment->payment_mode === 'cheque' ? $payment->cheque_status : null];
    }

    private function source(string $type, ?int $id, ?string $label): ?array
    {
        return $id && $label ? ['type' => $type, 'id' => $id, 'label' => $label] : null;
    }

    private function uniqueNavigation(array $navigation): array
    {
        $unique = [];
        foreach ($navigation as $target) {
            $unique[$target['route'].json_encode($target['query'] ?? [])] = $target;
        }

        return array_values($unique);
    }
}
