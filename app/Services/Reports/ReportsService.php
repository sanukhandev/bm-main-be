<?php

namespace App\Services\Reports;

use App\Models\AccountTransaction;
use App\Models\OwnerAgreement;
use App\Models\TenantAgreement;
use App\Support\Branch\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportsService
{
    private const TERMINAL_AGREEMENT_STATUSES = ['cancelled', 'terminated'];

    public function __construct(private readonly AgreementOutstandingQuery $outstanding)
    {
    }

    public function ownerAgreements(Request $request, BranchContext $context, bool $financial): array
    {
        $query = OwnerAgreement::query()->with(['owner', 'properties'])->where('branch_id', $context->id());
        $this->agreementFilters($query, $request, 'owner_customer_id');

        return $this->agreementResponse($query, 'owner', $financial, $request);
    }

    public function tenantAgreements(Request $request, BranchContext $context, bool $financial): array
    {
        $query = TenantAgreement::query()->with(['tenant', 'properties'])->where('branch_id', $context->id());
        $this->agreementFilters($query, $request, 'tenant_customer_id');

        return $this->agreementResponse($query, 'tenant', $financial, $request);
    }

    public function expiry(Request $request, BranchContext $context): array
    {
        $to = $request->query('date_to', now()->addDays(30)->toDateString());
        $from = $request->query('date_from');
        $types = $request->query('agreement_type', 'all');
        $rows = collect();

        if ($types !== 'tenant') {
            $rows = $rows->merge($this->expiryRows(OwnerAgreement::query()->with(['owner', 'properties'])->where('branch_id', $context->id()), 'owner', $from, $to, $request));
        }
        if ($types !== 'owner') {
            $rows = $rows->merge($this->expiryRows(TenantAgreement::query()->with(['tenant', 'properties'])->where('branch_id', $context->id()), 'tenant', $from, $to, $request));
        }

        $rows = $rows->sortBy('end_date')->values();

        return ['data' => $rows, 'meta' => ['total' => $rows->count(), 'date_from' => $from, 'date_to' => $to]];
    }

    public function tenantOutstanding(Request $request, BranchContext $context): array
    {
        return $this->installmentReport($request, $context, 'tenant');
    }

    public function ownerPayables(Request $request, BranchContext $context): array
    {
        return $this->installmentReport($request, $context, 'owner');
    }

    public function transactions(Request $request, BranchContext $context, string $direction): array
    {
        $query = AccountTransaction::query()->with('party')
            ->where('branch_id', $context->id())->where('direction', $direction)
            ->when($request->query('date_from'), fn ($q, $value) => $q->whereDate('transaction_date', '>=', $value))
            ->when($request->query('date_to'), fn ($q, $value) => $q->whereDate('transaction_date', '<=', $value))
            ->when($request->query('status'), fn ($q, $value) => $q->where('status', $value), fn ($q) => $q->where('status', 'posted'))
            ->when($request->query('payment_mode'), fn ($q, $value) => $q->where('payment_mode', $value))
            ->when($request->query('cheque_status'), fn ($q, $value) => $q->where('cheque_status', $value))
            ->when($request->query('source_type'), fn ($q, $value) => $q->where('source_type', $value))
            ->when($request->query('customer_id'), fn ($q, $value) => $q->where('party_customer_id', $value))
            ->when($request->query('search'), function ($q, $value) {
                $q->where(function ($inner) use ($value) {
                    $inner->where('document_no', 'like', "%{$value}%")
                        ->orWhere('remarks', 'like', "%{$value}%")
                        ->orWhere('cheque_no', 'like', "%{$value}%")
                        ->orWhere('bank_reference', 'like', "%{$value}%")
                        ->orWhereHas('party', fn ($party) => $party->where('display_name', 'like', "%{$value}%"));
                });
            })
            ->latest('transaction_date')->latest('id');

        $summary = $this->transactionSummary(clone $query);
        $page = $query->paginate($this->perPage($request));

        return $this->paginated($page->through(fn (AccountTransaction $row) => $this->transactionRow($row)), $summary);
    }

    public function cashMovement(Request $request, BranchContext $context): array
    {
        $query = AccountTransaction::query()->where('branch_id', $context->id())->where('status', 'posted')
            ->where('payment_mode', 'cash')->when($request->query('date_from'), fn ($q, $value) => $q->whereDate('transaction_date', '>=', $value))
            ->when($request->query('date_to'), fn ($q, $value) => $q->whereDate('transaction_date', '<=', $value));
        $rows = $query->selectRaw("transaction_date as date, SUM(CASE WHEN direction = 'inward' THEN amount ELSE 0 END) as cash_in, SUM(CASE WHEN direction = 'outward' THEN amount ELSE 0 END) as cash_out, COUNT(*) as transaction_count")
            ->groupBy('transaction_date')->orderBy('transaction_date')->get()->map(fn ($row) => [
                'date' => $row->date,
                'cash_in' => $row->cash_in,
                'cash_out' => $row->cash_out,
                'net' => number_format((float) $row->cash_in - (float) $row->cash_out, 2, '.', ''),
                'transaction_count' => (int) $row->transaction_count,
            ]);

        return ['data' => $rows, 'summary' => ['cash_in' => $rows->sum('cash_in'), 'cash_out' => $rows->sum('cash_out'), 'net' => number_format((float) $rows->sum('cash_in') - (float) $rows->sum('cash_out'), 2, '.', '')]];
    }

    public function pettyCash(Request $request, BranchContext $context): array
    {
        $from = $request->query('date_from', now()->startOfMonth()->toDateString());
        $to = $request->query('date_to', now()->toDateString());
        $base = AccountTransaction::query()->with('party')->where('branch_id', $context->id())->where('source_type', 'petty_cash')->where('status', 'posted');
        $opening = (float) $this->pettyBalanceBefore($context->id(), $from);
        $filtered = (clone $base)->whereBetween('transaction_date', [$from, $to])->when($request->query('direction'), fn ($q, $v) => $q->where('direction', $v))->when($request->query('search'), fn ($q, $v) => $q->where(function ($i) use ($v) {
            $i->where('document_no', 'like', "%{$v}%")->orWhere('remarks', 'like', "%{$v}%");
        }))->orderBy('transaction_date')->orderBy('id');
        $totalIn = (clone $filtered)->where('direction', 'inward')->sum('amount');
        $totalOut = (clone $filtered)->where('direction', 'outward')->sum('amount');
        $page = $filtered->paginate($this->perPage($request));
        $running = $opening;
        if ($first = $page->first()) {
            $before = (clone $base)->whereDate('transaction_date', '>=', $from)->where(function ($query) use ($first) {
                $query->where('transaction_date', '<', $first->transaction_date)->orWhere(fn ($q) => $q->where('transaction_date', $first->transaction_date)->where('id', '<', $first->id));
            });
            $before->when($request->query('direction'), fn ($q, $v) => $q->where('direction', $v));
            $before->when($request->query('search'), function ($q, $v) {
                $q->where('document_no', 'like', "%{$v}%")->orWhere('remarks', 'like', "%{$v}%");
            });
            $running += (float) $before->sum(DB::raw("CASE WHEN direction = 'inward' THEN amount ELSE -amount END"));
        }
        $page->setCollection($page->getCollection()->map(function (AccountTransaction $row) use (&$running) {
            $amount = (float) $row->amount;
            $running += $row->direction->value === 'inward' ? $amount : -$amount;

            return ['date' => $row->transaction_date?->format('Y-m-d'), 'document_no' => $row->document_no, 'direction' => $row->direction->value, 'particulars' => $row->remarks, 'amount' => $row->amount, 'cash_in' => $row->direction->value === 'inward' ? $row->amount : '0.00', 'cash_out' => $row->direction->value === 'outward' ? $row->amount : '0.00', 'running_balance' => number_format($running, 2, '.', ''), 'status' => $row->status->value];
        }));

        return $this->paginated($page, ['opening_balance' => number_format($opening, 2, '.', ''), 'cash_in' => $totalIn, 'cash_out' => $totalOut, 'closing_balance' => number_format($opening + (float) $totalIn - (float) $totalOut, 2, '.', '')]);
    }

    private function agreementFilters(Builder $query, Request $request, string $partyColumn): void
    {
        $query->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('customer_id'), fn ($q, $v) => $q->where($partyColumn, $v))
            ->when($request->query('owner_id'), fn ($q, $v) => $q->where('owner_customer_id', $v))
            ->when($request->query('tenant_id'), fn ($q, $v) => $q->where('tenant_customer_id', $v))
            ->when($request->query('date_from'), fn ($q, $v) => $q->whereDate('start_date', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->whereDate('end_date', '<=', $v))
            ->when($request->query('property_id'), fn ($q, $v) => $q->whereHas('properties', fn ($p) => $p->where('properties.id', $v)))
            ->when($request->query('search'), function ($q, $v) {
                $q->where(function ($inner) use ($v) {
                    $inner->where('agreement_no', 'like', "%{$v}%")->orWhereHas('owner', fn ($c) => $c->where('display_name', 'like', "%{$v}%"))->orWhereHas('tenant', fn ($c) => $c->where('display_name', 'like', "%{$v}%"))->orWhereHas('properties', fn ($p) => $p->where('name', 'like', "%{$v}%")->orWhere('property_code', 'like', "%{$v}%"));
                });
            })->orderByDesc('start_date')->orderByDesc('id');
    }

    private function agreementResponse(Builder $query, string $type, bool $financial, Request $request): array
    {
        $model = $type === 'owner' ? 'owner' : 'tenant';
        $table = $type === 'owner' ? 'owner_agreement_installments' : 'tenant_agreement_installments';
        $foreign = $type === 'owner' ? 'owner_agreement_id' : 'tenant_agreement_id';
        $ids = (clone $query)->select('id');
        $total = (clone $query)->sum('total_amount');
        $paid = DB::table($table)->whereIn($foreign, $ids)->sum('paid_amount');
        $page = $query->paginate($this->perPage($request));
        $page->setCollection($page->getCollection()->map(function ($agreement) use ($model, $financial, $table, $foreign, $type) {
            $paid = DB::table($table)->where($foreign, $agreement->id)->sum('paid_amount');

            return ['agreement_type' => $type, 'agreement_id' => $agreement->id, 'agreement_no' => $agreement->agreement_no, $model.'_id' => $agreement->{$model.'_customer_id'}, $model.'_name' => $agreement->{$model}?->display_name, 'properties' => $agreement->properties->map(fn ($p) => ['id' => $p->id, 'code' => $p->property_code, 'name' => $p->name])->values(), 'start_date' => $agreement->start_date?->format('Y-m-d'), 'end_date' => $agreement->end_date?->format('Y-m-d'), 'status' => $agreement->status, 'agreement_amount' => $financial ? $agreement->total_amount : null, 'paid_amount' => $financial ? number_format((float) $paid, 2, '.', '') : null, 'outstanding_amount' => $financial ? number_format((float) $agreement->total_amount - (float) $paid, 2, '.', '') : null, 'payment_count' => $agreement->payment_count, 'created_at' => $agreement->created_at?->toIso8601String()];
        }));

        return $this->paginated($page, ['total_agreements' => $page->total(), 'total_agreement_value' => $financial ? $total : null, 'total_paid' => $financial ? $paid : null, 'total_outstanding' => $financial ? number_format((float) $total - (float) $paid, 2, '.', '') : null]);
    }

    private function expiryRows(Builder $query, string $type, ?string $from, string $to, Request $request): Collection
    {
        $query->whereDate('end_date', '<=', $to)->when($from, fn ($q) => $q->whereDate('end_date', '>=', $from))->whereNotIn('status', ['cancelled', 'terminated'])->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))->when($request->query('search'), fn ($q, $v) => $q->where('agreement_no', 'like', "%{$v}%"));

        return $query->get()->map(fn ($agreement) => ['agreement_type' => $type, 'agreement_id' => $agreement->id, 'agreement_no' => $agreement->agreement_no, 'customer' => $agreement->{$type}?->display_name, 'properties' => $agreement->properties->pluck('name')->values(), 'start_date' => $agreement->start_date?->format('Y-m-d'), 'end_date' => $agreement->end_date?->format('Y-m-d'), 'status' => $agreement->status, 'days_remaining' => now()->startOfDay()->diffInDays($agreement->end_date, false)]);
    }

    private function installmentReport(Request $request, BranchContext $context, string $type): array
    {
        $partyColumn = $type === 'tenant' ? 'tenant_customer_id' : 'owner_customer_id';
        [$table, $agreementTable, $foreign] = $this->outstanding->tables($type);
        $query = $this->outstanding->unpaid([$context->id()], $type)->when($request->query('date_from'), fn ($q, $v) => $q->whereDate('installments.due_date', '>=', $v))->when($request->query('date_to'), fn ($q, $v) => $q->whereDate('installments.due_date', '<=', $v))->when($request->query('customer_id'), fn ($q, $v) => $q->where('agreements.'.$partyColumn, $v))->when($request->query('agreement_id'), fn ($q, $v) => $q->where('agreements.id', $v))->when($request->query('overdue_only'), fn ($q) => $q->whereDate('installments.due_date', '<', now()->toDateString()))->when($request->query('search'), fn ($q, $v) => $q->where(function ($i) use ($v) {
            $i->where('agreements.agreement_no', 'like', "%{$v}%")->orWhere('customers.display_name', 'like', "%{$v}%");
        }))->orderBy('installments.due_date');
        $summaryQuery = clone $query;
        $summary = ['total_outstanding' => $summaryQuery->sum(DB::raw('installments.amount - installments.paid_amount')), 'total_overdue' => (clone $query)->whereDate('installments.due_date', '<', now()->toDateString())->sum(DB::raw('installments.amount - installments.paid_amount')), 'overdue_installments' => (clone $query)->whereDate('installments.due_date', '<', now()->toDateString())->count(), 'parties_with_outstanding' => (clone $query)->distinct('agreements.'.$partyColumn)->count('agreements.'.$partyColumn)];
        $page = $query->paginate($this->perPage($request));

        return $this->paginated($page->through(fn ($row) => ['installment_id' => $row->id, 'party_id' => $row->{$partyColumn}, 'party_name' => $row->party_name, 'agreement_id' => $row->{$foreign}, 'agreement_type' => $type, 'agreement_no' => $row->agreement_no, 'installment_no' => $row->installment_no, 'due_date' => $row->due_date, 'amount' => $row->amount, 'paid_amount' => $row->paid_amount, 'outstanding_amount' => number_format((float) $row->amount - (float) $row->paid_amount, 2, '.', ''), 'days_overdue' => max(0, now()->startOfDay()->diffInDays($row->due_date, false) * -1), 'status' => $row->status]), $summary);
    }

    private function transactionSummary(Builder $query): array
    {
        return ['transaction_count' => $query->count(), 'total_amount' => $query->sum('amount'), 'cash_total' => (clone $query)->where('payment_mode', 'cash')->sum('amount'), 'cheque_total' => (clone $query)->where('payment_mode', 'cheque')->sum('amount'), 'bank_transfer_total' => (clone $query)->where('payment_mode', 'bank_transfer')->sum('amount')];
    }

    private function transactionRow(AccountTransaction $row): array
    {
        return ['id' => $row->id, 'document_no' => $row->document_no, 'transaction_date' => $row->transaction_date?->format('Y-m-d'), 'party' => $row->party?->display_name, 'party_id' => $row->party_customer_id, 'direction' => $row->direction?->value, 'source_type' => $row->source_type, 'source_id' => $row->source_id, 'payment_mode' => $row->payment_mode?->value, 'amount' => $row->amount, 'status' => $row->status?->value, 'cheque_no' => $row->cheque_no, 'cheque_status' => $row->cheque_status?->value, 'bank_reference' => $row->bank_reference, 'remarks' => $row->remarks, 'posted_at' => $row->posted_at?->toIso8601String()];
    }

    private function pettyBalanceBefore(int $branchId, string $date): string
    {
        $in = AccountTransaction::query()->where('branch_id', $branchId)->where('source_type', 'petty_cash')->where('status', 'posted')->where('direction', 'inward')->whereDate('transaction_date', '<', $date)->sum('amount');
        $out = AccountTransaction::query()->where('branch_id', $branchId)->where('source_type', 'petty_cash')->where('status', 'posted')->where('direction', 'outward')->whereDate('transaction_date', '<', $date)->sum('amount');

        return number_format((float) $in - (float) $out, 2, '.', '');
    }

    private function paginated($page, array $summary): array
    {
        return ['data' => $page->items(), 'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()], 'meta' => ['current_page' => $page->currentPage(), 'from' => $page->firstItem(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'to' => $page->lastItem(), 'total' => $page->total(), 'summary' => $summary]];
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 25), 1), 100);
    }
}
