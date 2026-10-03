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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class Owner360Skill implements ZaakiyReadSkill
{
    private const ACTIVE_STATUSES = ['approved', 'commenced', 'on_hold'];

    public function __construct(private readonly EntityResolver $entities) {}

    public function supports(IntentFrame $intent): bool
    {
        return $intent->intent === 'owner_360' || in_array('owner_360', $intent->modules, true);
    }

    public function execute(ZaakiyExecutionContext $context): ZaakiySkillResult
    {
        $matches = $this->entities->resolveOwners($context->intent->question, $context);
        if (count($matches) !== 1) {
            return $this->unresolved($matches);
        }

        $owner = $matches[0];
        $branchId = $context->branchId();
        $financial = $context->can('accounts.view');
        $properties = Property::query()->forBranch($branchId)->where('owner_customer_id', $owner->id)->latest('id')->get();
        $propertyIds = $properties->pluck('id')->all();
        $tenantAgreements = $this->currentTenantAgreements($branchId, $propertyIds);
        $occupiedPropertyIds = $tenantAgreements->flatMap->properties->pluck('id')->unique()->all();
        $agreements = OwnerAgreement::query()->forBranch($branchId)->where('owner_customer_id', $owner->id)
            ->whereNotIn('status', ['draft', 'cancelled', 'terminated'])->with('properties')->latest('id')->limit(5)->get();
        $activeAgreements = $agreements->filter(fn (OwnerAgreement $agreement): bool => in_array($agreement->status, self::ACTIVE_STATUSES, true)
            && $agreement->start_date?->toDateString() <= now()->toDateString()
            && $agreement->end_date?->toDateString() >= now()->toDateString())->values();
        $agreementIds = OwnerAgreement::query()->forBranch($branchId)->where('owner_customer_id', $owner->id)
            ->whereNotIn('status', ['draft', 'cancelled', 'terminated'])->pluck('id')->all();

        $records = [$this->ownerRecord($owner)];
        $sources = [$this->source('owner', $owner->id, $owner->customer_code)];
        $navigation = [['label' => 'View owner', 'route' => '/app/customers/'.$owner->id, 'query' => []]];
        $suggestions = ['Which properties are vacant?'];

        foreach ($properties->take(10) as $property) {
            $occupancy = in_array($property->id, $occupiedPropertyIds, true) ? 'occupied' : 'vacant';
            $records[] = $this->propertyRecord($property, $occupancy);
            $sources[] = $this->source('property', $property->id, $property->property_code);
            $navigation[] = ['label' => 'View property', 'route' => '/app/properties/'.$property->id, 'query' => []];
        }
        foreach ($activeAgreements as $agreement) {
            $records[] = $this->agreementRecord($agreement, $financial);
            $sources[] = $this->source('owner_agreement', $agreement->id, $agreement->agreement_no);
            $navigation[] = ['label' => 'View owner agreement', 'route' => '/app/owner-agreements/'.$agreement->id, 'query' => []];
        }
        foreach ($agreements->reject(fn (OwnerAgreement $agreement): bool => $activeAgreements->contains('id', $agreement->id))->take(5) as $agreement) {
            $records[] = $this->agreementRecord($agreement, $financial, 'recent_owner_agreement');
            $sources[] = $this->source('owner_agreement', $agreement->id, $agreement->agreement_no);
        }

        $openWorkOrders = DB::table('work_orders')->where('branch_id', $branchId)->whereIn('property_id', $propertyIds)->whereNotIn('status', ['completed', 'cancelled']);
        $metrics = [
            'property_count' => $properties->count(),
            'occupied_property_count' => count($occupiedPropertyIds),
            'vacant_property_count' => max(0, $properties->count() - count($occupiedPropertyIds)),
            'active_owner_agreement_count' => $activeAgreements->count(),
            'open_work_order_count' => (clone $openWorkOrders)->count(),
            'properties_with_open_work_orders' => (clone $openWorkOrders)->distinct('property_id')->count('property_id'),
        ];
        $nearestExpiry = $activeAgreements->sortBy('end_date')->first()?->end_date;
        if ($nearestExpiry) {
            $metrics['nearest_owner_agreement_expiry_date'] = $nearestExpiry->format('Y-m-d');
            $metrics['agreements_expiring_within_30_days'] = $activeAgreements->filter(fn (OwnerAgreement $agreement): bool => $this->daysUntil($agreement->end_date) <= 30)->count();
        }
        if ($financial && $agreementIds !== []) {
            $metrics += $this->financialMetrics($branchId, $agreementIds, $records, $sources);
            $navigation[] = ['label' => 'View owner payments', 'route' => '/app/accounts/outward', 'query' => ['party_customer_id' => $owner->id]];
            $suggestions[] = 'Do we owe this owner anything?';
            $suggestions[] = 'Show upcoming owner payments.';
            if (($metrics['pending_owner_cheque_count'] ?? 0) > 0) {
                $suggestions[] = 'Are any owner cheques pending?';
            }
        } else {
            $suggestions[] = 'When does the next agreement expire?';
        }
        if ($metrics['open_work_order_count'] > 0) {
            $navigation[] = ['label' => 'View maintenance work orders', 'route' => '/app/maintenance/work-orders', 'query' => []];
            $suggestions[] = 'Which properties have maintenance issues?';
        }

        return new ZaakiySkillResult(
            intent: 'owner_360',
            subject: (string) ($owner->display_name ?: $owner->customer_code),
            summaryMetrics: $metrics,
            records: $records,
            warnings: $financial ? [] : [['code' => 'FINANCIAL_DATA_RESTRICTED', 'message' => 'Financial information requires accounts.view.']],
            sources: array_values(array_filter($sources, fn (?array $source): bool => $source !== null)),
            navigation: $this->uniqueNavigation($navigation),
            suggestedFollowups: array_values(array_unique(array_slice($suggestions, 0, 5))),
            meta: ['result_type' => 'owner_360', 'branch_scoped' => true, 'financial_included' => $financial, 'record_count' => count($records)],
        );
    }

    private function currentTenantAgreements(int $branchId, array $propertyIds): Collection
    {
        if ($propertyIds === []) {
            return collect();
        }

        return TenantAgreement::query()->forBranch($branchId)->whereIn('status', self::ACTIVE_STATUSES)
            ->whereDate('start_date', '<=', now()->toDateString())->whereDate('end_date', '>=', now()->toDateString())
            ->whereHas('properties', fn ($query) => $query->whereIn('properties.id', $propertyIds))->with('properties')->get();
    }

    private function financialMetrics(int $branchId, array $agreementIds, array &$records, array &$sources): array
    {
        $installments = DB::table('owner_agreement_installments')->where('branch_id', $branchId)->whereIn('owner_agreement_id', $agreementIds)->whereColumn('paid_amount', '<', 'amount');
        $outstanding = (float) (clone $installments)->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total');
        $overdueQuery = (clone $installments)->whereDate('due_date', '<', now()->toDateString());
        $overdue = (float) (clone $overdueQuery)->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total');
        $next = (clone $installments)->orderBy('due_date')->first(['id', 'installment_no', 'due_date', 'amount', 'paid_amount', 'payment_mode']);
        if ($next) {
            $records[] = ['type' => 'next_owner_installment', 'id' => $next->id, 'installment_no' => $next->installment_no, 'due_date' => $next->due_date, 'amount' => $next->amount, 'paid_amount' => $next->paid_amount, 'balance' => number_format((float) $next->amount - (float) $next->paid_amount, 2, '.', ''), 'payment_mode' => $next->payment_mode];
            $sources[] = $this->source('installment', $next->id, 'Installment '.$next->installment_no);
        }
        $paymentQuery = $this->paymentQuery($branchId, $agreementIds);
        $last = (clone $paymentQuery)->orderByDesc('transactions.transaction_date')->orderByDesc('transactions.id')->first();
        if ($last) {
            $records[] = $this->paymentRecord($last, 'last_owner_payment');
            $sources[] = $this->source('payment', $last->id, $last->document_no);
        }
        foreach ((clone $paymentQuery)->orderByDesc('transactions.transaction_date')->orderByDesc('transactions.id')->limit(3)->get() as $payment) {
            if (! $last || (int) $payment->id !== (int) $last->id) {
                $records[] = $this->paymentRecord($payment, 'recent_owner_payment');
                $sources[] = $this->source('payment', $payment->id, $payment->document_no);
            }
        }
        $chequeCounts = (clone $paymentQuery)->where('transactions.payment_mode', 'cheque')->select('transactions.cheque_status', DB::raw('COUNT(DISTINCT transactions.id) as count'))->groupBy('transactions.cheque_status')->pluck('count', 'cheque_status');

        return [
            'owner_payable' => number_format($outstanding, 2, '.', ''),
            'overdue_owner_payable' => number_format($overdue, 2, '.', ''),
            'unpaid_owner_installment_count' => (clone $installments)->count(),
            'overdue_owner_installment_count' => (clone $overdueQuery)->count(),
            'pending_owner_cheque_count' => (int) ($chequeCounts['received'] ?? 0) + (int) ($chequeCounts['deposited'] ?? 0),
            'deposited_owner_cheque_count' => (int) ($chequeCounts['deposited'] ?? 0),
            'bounced_owner_cheque_count' => (int) ($chequeCounts['bounced'] ?? 0),
        ];
    }

    private function paymentQuery(int $branchId, array $agreementIds)
    {
        return DB::table('account_transactions as transactions')
            ->join('account_transaction_allocations as allocations', 'allocations.account_transaction_id', '=', 'transactions.id')
            ->join('owner_agreement_installments as installments', 'installments.id', '=', 'allocations.owner_agreement_installment_id')
            ->where('transactions.branch_id', $branchId)->where('transactions.direction', 'outward')->where('transactions.status', 'posted')
            ->where('allocations.branch_id', $branchId)->where('installments.branch_id', $branchId)
            ->whereIn('installments.owner_agreement_id', $agreementIds)->whereNotNull('allocations.owner_agreement_installment_id')
            ->select(['transactions.id', 'transactions.document_no', 'transactions.transaction_date', 'transactions.direction', 'transactions.payment_mode', 'transactions.amount', 'transactions.cheque_status'])
            ->distinct();
    }

    private function ownerRecord(Customer $owner): array
    {
        return array_filter(['type' => 'owner', 'id' => $owner->id, 'customer_code' => $owner->customer_code, 'display_name' => $owner->display_name, 'legal_name' => $owner->legal_name, 'customer_type' => $owner->customer_type, 'status' => $owner->status], static fn ($value) => $value !== null && $value !== '');
    }

    private function propertyRecord(Property $property, string $occupancy): array
    {
        return array_filter(['type' => 'property', 'id' => $property->id, 'property_code' => $property->property_code, 'unit_number' => $property->unit_number, 'name' => $property->name, 'property_type' => $property->property_type instanceof \BackedEnum ? $property->property_type->value : $property->property_type, 'status' => $property->status, 'occupancy_status' => $occupancy, 'city' => $property->city, 'state_or_emirate' => $property->state_or_emirate], static fn ($value) => $value !== null && $value !== '');
    }

    private function agreementRecord(OwnerAgreement $agreement, bool $financial, string $type = 'owner_agreement'): array
    {
        $record = ['type' => $type, 'id' => $agreement->id, 'agreement_no' => $agreement->agreement_no, 'start_date' => $agreement->start_date?->format('Y-m-d'), 'end_date' => $agreement->end_date?->format('Y-m-d'), 'status' => $agreement->status, 'days_until_expiry' => $this->daysUntil($agreement->end_date), 'properties' => $agreement->properties->take(10)->map(fn (Property $property): array => ['id' => $property->id, 'property_code' => $property->property_code, 'name' => $property->name])->values()->all()];
        if ($financial) {
            $record['total_amount'] = $agreement->total_amount;
        }

        return $record;
    }

    private function paymentRecord($payment, string $type): array
    {
        return ['type' => $type, 'id' => $payment->id, 'document_no' => $payment->document_no, 'transaction_date' => $payment->transaction_date, 'direction' => $payment->direction, 'payment_mode' => $payment->payment_mode, 'amount' => $payment->amount, 'cheque_status' => $payment->payment_mode === 'cheque' ? $payment->cheque_status : null];
    }

    private function unresolved(array $matches): ZaakiySkillResult
    {
        return new ZaakiySkillResult(
            intent: 'owner_360',
            subject: 'Owner summary',
            records: array_map(fn (Customer $owner): array => $this->ownerRecord($owner), array_slice($matches, 0, 5)),
            warnings: [[
                'code' => $matches === [] ? 'OWNER_NOT_FOUND' : 'OWNER_AMBIGUOUS',
                'message' => $matches === [] ? 'No authorized owner matched the request.' : 'More than one authorized owner matched; no owner was selected.',
            ]],
            meta: ['result_type' => 'owner_360', 'branch_scoped' => true, 'record_count' => count($matches)],
        );
    }

    private function source(string $type, ?int $id, ?string $label): ?array
    {
        return $id && $label ? ['type' => $type, 'id' => $id, 'label' => $label] : null;
    }

    private function daysUntil($date): int
    {
        return max(0, now()->startOfDay()->diffInDays($date, false));
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
