<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\Customer;
use App\Models\OwnerAgreement;
use App\Models\Property;
use App\Models\TenantAgreement;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\EntityResolver;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Services\Zaakiy\ZaakiyReadSkill;
use Illuminate\Support\Facades\DB;

final class Agreement360Skill implements ZaakiyReadSkill
{
    public function __construct(private readonly EntityResolver $entities) {}

    public function supports(IntentFrame $intent): bool
    {
        return $intent->intent === 'agreement_360' || in_array('agreement_360', $intent->modules, true);
    }

    public function execute(ZaakiyExecutionContext $context): ZaakiySkillResult
    {
        $matches = $this->entities->resolveAgreements($context->intent->question, $context);
        if (count($matches) !== 1) {
            return $this->unresolved($matches);
        }

        $type = $matches[0]['type'];
        $agreement = $matches[0]['agreement'];
        $branchId = $context->branchId();
        $financial = $context->can('accounts.view');
        $party = $type === 'owner_agreement' ? $agreement->owner : $agreement->tenant;
        $properties = $agreement->properties->take(10);
        $records = [$this->agreementRecord($agreement, $type, $financial)];
        $sources = [$this->source($type, $agreement->id, $agreement->agreement_no)];
        $navigation = [['label' => 'View agreement', 'route' => '/app/'.($type === 'owner_agreement' ? 'owner-agreements' : 'tenant-agreements').'/'.$agreement->id, 'query' => []]];
        $suggestions = ['When does this agreement expire?', 'Show the property.'];

        if ($party instanceof Customer) {
            $partyType = $type === 'owner_agreement' ? 'owner' : 'tenant';
            $records[] = $this->partyRecord($party, $partyType);
            $sources[] = $this->source('customer', $party->id, $party->customer_code);
            $navigation[] = ['label' => 'View '.($type === 'owner_agreement' ? 'owner' : 'tenant'), 'route' => '/app/customers/'.$party->id, 'query' => []];
            $suggestions[] = 'Tell me about the '.($type === 'owner_agreement' ? 'owner' : 'tenant').'.';
        }
        foreach ($properties as $property) {
            $records[] = $this->propertyRecord($property);
            $sources[] = $this->source('property', $property->id, $property->property_code);
            $navigation[] = ['label' => 'View property', 'route' => '/app/properties/'.$property->id, 'query' => []];
        }

        $metrics = [
            'days_until_expiry' => $this->daysUntil($agreement->end_date),
            'days_until_start' => $this->daysUntil($agreement->start_date),
            'property_count' => $agreement->properties->count(),
        ];
        if ($financial) {
            $metrics += $this->financialMetrics($branchId, $agreement, $type, $records, $sources);
            $navigation[] = ['label' => 'View '.($type === 'owner_agreement' ? 'outward' : 'inward').' payments', 'route' => '/app/accounts/'.($type === 'owner_agreement' ? 'outward' : 'inward'), 'query' => ['source_id' => $agreement->id]];
            $suggestions[] = 'Show the next installment.';
            $suggestions[] = 'Show recent payments.';
            $suggestions[] = 'How much is overdue?';
        }

        return new ZaakiySkillResult(
            intent: 'agreement_360',
            subject: (string) $agreement->agreement_no,
            summaryMetrics: $metrics,
            records: $records,
            warnings: $financial ? [] : [['code' => 'FINANCIAL_DATA_RESTRICTED', 'message' => 'Financial information requires accounts.view.']],
            sources: array_values(array_filter($sources, fn (?array $source): bool => $source !== null)),
            navigation: $this->uniqueNavigation($navigation),
            suggestedFollowups: array_values(array_unique(array_slice($suggestions, 0, 5))),
            meta: ['result_type' => 'agreement_360', 'agreement_type' => $type, 'financial_included' => $financial, 'financial_direction' => $type === 'owner_agreement' ? 'outward' : 'inward', 'branch_scoped' => true, 'record_count' => count($records)],
        );
    }

    private function financialMetrics(int $branchId, OwnerAgreement|TenantAgreement $agreement, string $type, array &$records, array &$sources): array
    {
        $prefix = $type === 'owner_agreement' ? 'owner_' : 'tenant_';
        $table = $type === 'owner_agreement' ? 'owner_agreement_installments' : 'tenant_agreement_installments';
        $foreign = $type === 'owner_agreement' ? 'owner_agreement_id' : 'tenant_agreement_id';
        $installments = DB::table($table)->where('branch_id', $branchId)->where($foreign, $agreement->id);
        $total = (float) (clone $installments)->sum('amount');
        $paid = (float) (clone $installments)->sum('paid_amount');
        $unpaid = (clone $installments)->whereColumn('paid_amount', '<', 'amount');
        $overdue = (clone $unpaid)->whereDate('due_date', '<', now()->toDateString());
        $next = (clone $unpaid)->orderBy('due_date')->first(['id', 'installment_no', 'due_date', 'amount', 'paid_amount', 'payment_mode']);
        if ($next) {
            $records[] = ['type' => 'next_installment', 'id' => $next->id, 'installment_no' => $next->installment_no, 'due_date' => $next->due_date, 'amount' => $next->amount, 'paid_amount' => $next->paid_amount, 'balance' => number_format(max(0, (float) $next->amount - (float) $next->paid_amount), 2, '.', ''), 'payment_mode' => $next->payment_mode];
            $sources[] = $this->source('installment', $next->id, 'Installment '.$next->installment_no);
        }

        $payments = $this->paymentQuery($branchId, $agreement->id, $type, $foreign);
        $recent = (clone $payments)->orderByDesc('transactions.transaction_date')->orderByDesc('transactions.id')->limit(3)->get();
        foreach ($recent as $payment) {
            $records[] = $this->paymentRecord($payment, $type);
            $sources[] = $this->source('payment', $payment->id, $payment->document_no);
            $receiptType = $type === 'owner_agreement' ? 'purchase_receipt' : 'cash_receipt';
            $records[] = ['type' => 'receipt_reference', 'receipt_type' => $receiptType, 'payment_id' => $payment->id, 'document_no' => $payment->document_no];
            $sources[] = $this->source($receiptType, $payment->id, $payment->document_no);
        }
        $cheques = (clone $payments)->where('transactions.payment_mode', 'cheque')->select('transactions.cheque_status', DB::raw('COUNT(DISTINCT transactions.id) as count'))->groupBy('transactions.cheque_status')->pluck('count', 'cheque_status');
        $paidInstallments = (clone $installments)->whereColumn('paid_amount', '>=', 'amount')->count();
        $partialInstallments = (clone $installments)->whereColumn('paid_amount', '>', DB::raw('0'))->whereColumn('paid_amount', '<', 'amount')->count();

        return [
            'scheduled_total' => number_format($total, 2, '.', ''),
            'paid_total' => number_format($paid, 2, '.', ''),
            'remaining_total' => number_format(max(0, $total - $paid), 2, '.', ''),
            'payment_completion_percentage' => $total > 0 ? round(min(100, max(0, ($paid / $total) * 100)), 2) : null,
            'outstanding' => number_format(max(0, $total - $paid), 2, '.', ''),
            'overdue' => number_format((float) (clone $overdue)->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'), 2, '.', ''),
            'installment_count' => (clone $installments)->count(),
            'paid_installment_count' => $paidInstallments,
            'partially_paid_installment_count' => $partialInstallments,
            'unpaid_installment_count' => (clone $unpaid)->count(),
            'overdue_installment_count' => (clone $overdue)->count(),
            'received_cheque_count' => (int) ($cheques['received'] ?? 0),
            'deposited_cheque_count' => (int) ($cheques['deposited'] ?? 0),
            'cleared_cheque_count' => (int) ($cheques['cleared'] ?? 0),
            'bounced_cheque_count' => (int) ($cheques['bounced'] ?? 0),
            'cancelled_cheque_count' => (int) ($cheques['cancelled'] ?? 0),
        ];
    }

    private function paymentQuery(int $branchId, int $agreementId, string $type, string $foreign)
    {
        $installmentTable = $type === 'owner_agreement' ? 'owner_agreement_installments' : 'tenant_agreement_installments';
        $allocation = $type === 'owner_agreement' ? 'owner_agreement_installment_id' : 'tenant_agreement_installment_id';
        $direction = $type === 'owner_agreement' ? 'outward' : 'inward';

        return DB::table('account_transactions as transactions')
            ->join('account_transaction_allocations as allocations', 'allocations.account_transaction_id', '=', 'transactions.id')
            ->join($installmentTable.' as installments', 'installments.id', '=', 'allocations.'.$allocation)
            ->where('transactions.branch_id', $branchId)->where('transactions.direction', $direction)->where('transactions.status', 'posted')
            ->where('allocations.branch_id', $branchId)->where('installments.branch_id', $branchId)->where('installments.'.$foreign, $agreementId)
            ->select(['transactions.id', 'transactions.document_no', 'transactions.transaction_date', 'transactions.direction', 'transactions.payment_mode', 'transactions.amount', 'transactions.cheque_status'])
            ->distinct();
    }

    private function agreementRecord(OwnerAgreement|TenantAgreement $agreement, string $type, bool $financial): array
    {
        $record = ['type' => $type, 'id' => $agreement->id, 'agreement_no' => $agreement->agreement_no, 'status' => $agreement->status, 'start_date' => $agreement->start_date?->format('Y-m-d'), 'end_date' => $agreement->end_date?->format('Y-m-d'), 'days_until_expiry' => $this->daysUntil($agreement->end_date), 'payment_count' => $agreement->payment_count, 'payment_frequency' => $agreement->payment_frequency, 'properties' => $agreement->properties->take(10)->map(fn (Property $property): array => ['id' => $property->id, 'property_code' => $property->property_code, 'unit_number' => $property->unit_number, 'name' => $property->name, 'property_type' => $property->property_type instanceof \BackedEnum ? $property->property_type->value : $property->property_type, 'status' => $property->status])->values()->all()];
        if ($financial) {
            $record['total_amount'] = $agreement->total_amount;
            $record['payment_mode'] = $agreement->payment_mode;
        } else {
            unset($record['payment_count'], $record['payment_frequency']);
        }

        return $record;
    }

    private function partyRecord(Customer $party, string $type): array
    {
        return array_filter(['type' => $type, 'id' => $party->id, 'customer_code' => $party->customer_code, 'display_name' => $party->display_name, 'legal_name' => $party->legal_name, 'customer_type' => $party->customer_type, 'status' => $party->status], static fn ($value) => $value !== null && $value !== '');
    }

    private function propertyRecord(Property $property): array
    {
        return array_filter(['type' => 'property', 'id' => $property->id, 'property_code' => $property->property_code, 'unit_number' => $property->unit_number, 'name' => $property->name, 'property_type' => $property->property_type instanceof \BackedEnum ? $property->property_type->value : $property->property_type, 'status' => $property->status, 'city' => $property->city, 'state_or_emirate' => $property->state_or_emirate], static fn ($value) => $value !== null && $value !== '');
    }

    private function paymentRecord($payment, string $type): array
    {
        return ['type' => 'recent_payment', 'id' => $payment->id, 'agreement_type' => $type, 'document_no' => $payment->document_no, 'transaction_date' => $payment->transaction_date, 'direction' => $payment->direction, 'payment_mode' => $payment->payment_mode, 'amount' => $payment->amount, 'cheque_status' => $payment->payment_mode === 'cheque' ? $payment->cheque_status : null];
    }

    private function unresolved(array $matches): ZaakiySkillResult
    {
        return new ZaakiySkillResult(
            intent: 'agreement_360',
            subject: 'Agreement summary',
            records: array_map(fn (array $match): array => ['type' => $match['type'], 'id' => $match['agreement']->id, 'agreement_no' => $match['agreement']->agreement_no, 'status' => $match['agreement']->status], array_slice($matches, 0, 5)),
            warnings: [[
                'code' => $matches === [] ? 'AGREEMENT_NOT_FOUND' : 'AGREEMENT_AMBIGUOUS',
                'message' => $matches === [] ? 'No authorized agreement matched the request.' : 'More than one authorized agreement matched; no agreement was selected.',
            ]],
            meta: ['result_type' => 'agreement_360', 'branch_scoped' => true, 'record_count' => count($matches)],
        );
    }

    private function source(string $type, ?int $id, ?string $label): ?array
    {
        return $id && $label ? ['type' => $type, 'id' => $id, 'label' => $label] : null;
    }

    private function daysUntil($date): int
    {
        return now()->startOfDay()->diffInDays($date, false);
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
