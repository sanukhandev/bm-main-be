<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreatePettyCashEntry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounts\CreatePettyCashRequest;
use App\Http\Resources\Api\V1\AccountTransactionResource;
use App\Models\AccountTransaction;
use App\Support\Branch\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountsController extends Controller
{
    public function dashboard(BranchContext $context): array
    {
        $branchId = $context->id();
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $base = AccountTransaction::query()->where('branch_id', $branchId)->where('status', 'posted');
        $sum = fn ($from, $to, $direction) => (clone $base)->where('direction', $direction)->whereBetween('transaction_date', [$from, $to])->sum('amount');
        $outstanding = fn ($table) => DB::table($table)->where('branch_id', $branchId)->whereIn('status', ['pending', 'partially_paid'])->selectRaw('COALESCE(SUM(amount - paid_amount), 0) AS total')->value('total');

        $todayInward = $sum($today, $today, 'inward');
        $todayOutward = $sum($today, $today, 'outward');
        $monthInward = $sum($monthStart, $today, 'inward');
        $monthOutward = $sum($monthStart, $today, 'outward');
        $recentTransactions = (clone $base)->with('party')->latest('transaction_date')->latest('id')->limit(5)->get()->map(fn ($transaction) => [
            'id' => $transaction->id,
            'document_no' => $transaction->document_no,
            'direction' => $transaction->direction->value,
            'transaction_date' => $transaction->transaction_date->format('Y-m-d'),
            'payment_mode' => $transaction->payment_mode->value,
            'amount' => $transaction->amount,
            'status' => $transaction->status->value,
            'party' => $transaction->party?->display_name,
        ]);

        return [
            'data' => [
                'today_inward' => $todayInward,
                'today_outward' => $todayOutward,
                'today_net_movement' => number_format((float) $todayInward - (float) $todayOutward, 2, '.', ''),
                'month_inward' => $monthInward,
                'month_outward' => $monthOutward,
                'month_net_movement' => number_format((float) $monthInward - (float) $monthOutward, 2, '.', ''),
                'petty_cash_balance' => $this->pettyBalance($branchId),
                'tenant_outstanding_receivable' => $outstanding('tenant_agreement_installments'),
                'owner_outstanding_payable' => $outstanding('owner_agreement_installments'),
                'pending_cheque_inward' => (clone $base)->where('direction', 'inward')->where('payment_mode', 'cheque')->sum('amount'),
                'pending_cheque_outward' => (clone $base)->where('direction', 'outward')->where('payment_mode', 'cheque')->sum('amount'),
                'recent_transactions' => $recentTransactions,
            ],
        ];
    }

    public function inward(Request $request, BranchContext $context)
    {
        return $this->transactions($request, $context, 'inward');
    }

    public function outward(Request $request, BranchContext $context)
    {
        return $this->transactions($request, $context, 'outward');
    }

    public function pettyCash(CreatePettyCashRequest $request, BranchContext $context, CreatePettyCashEntry $action): AccountTransactionResource
    {
        return new AccountTransactionResource($action->execute($context->branch(), $request->validated(), $request->user()->getAuthIdentifier()));
    }

    public function pettyDaybook(Request $request, BranchContext $context)
    {
        $from = $request->query('date_from', now()->startOfMonth()->toDateString());
        $to = $request->query('date_to', now()->toDateString());
        $base = AccountTransaction::query()->where('branch_id', $context->id())->where('source_type', 'petty_cash')->where('status', 'posted');
        $opening = $this->pettyBalanceBefore($context->id(), $from);
        $totalIn = (clone $base)->where('direction', 'inward')->whereBetween('transaction_date', [$from, $to])->sum('amount');
        $totalOut = (clone $base)->where('direction', 'outward')->whereBetween('transaction_date', [$from, $to])->sum('amount');
        $rows = (clone $base)->with('party')->whereBetween('transaction_date', [$from, $to])->orderBy('transaction_date')->orderBy('id')->paginate(min((int) $request->query('per_page', 25), 100));
        $beforePage = (clone $base)->where(function ($query) use ($rows) {
            $first = $rows->first();
            if ($first) {
                $query->where('transaction_date', '<', $first->transaction_date)->orWhere(fn ($q) => $q->where('transaction_date', $first->transaction_date)->where('id', '<', $first->id));
            }
        })->sum(DB::raw("CASE WHEN direction = 'inward' THEN amount ELSE -amount END"));
        $running = (float) $opening + (float) $beforePage;
        $data = $rows->getCollection()->map(function ($row) use (&$running) {
            $running += $row->direction->value === 'inward' ? (float) $row->amount : -(float) $row->amount;

            return [...(new AccountTransactionResource($row))->resolve(), 'cash_in' => $row->direction->value === 'inward' ? $row->amount : '0.00', 'cash_out' => $row->direction->value === 'outward' ? $row->amount : '0.00', 'running_balance' => number_format($running, 2, '.', '')];
        });

        $pagination = $rows->toArray();
        unset($pagination['data']);

        return response()->json(['data' => $data, 'links' => $rows->linkCollection(), 'meta' => [...$pagination, 'opening_balance' => number_format($opening, 2, '.', ''), 'total_in' => $totalIn, 'total_out' => $totalOut, 'closing_balance' => number_format((float) $opening + (float) $totalIn - (float) $totalOut, 2, '.', '')]]);
    }

    public function dailyMovement(Request $request, BranchContext $context)
    {
        $from = $request->query('date_from', now()->startOfMonth()->toDateString());
        $to = $request->query('date_to', now()->toDateString());
        $rows = AccountTransaction::query()->where('branch_id', $context->id())->where('status', 'posted')->whereBetween('transaction_date', [$from, $to])
            ->selectRaw("transaction_date, SUM(CASE WHEN direction = 'inward' THEN amount ELSE 0 END) AS inward, SUM(CASE WHEN direction = 'outward' THEN amount ELSE 0 END) AS outward")
            ->groupBy('transaction_date')->orderBy('transaction_date')->get()
            ->map(fn ($row) => ['date' => $row->transaction_date, 'inward' => $row->inward, 'outward' => $row->outward, 'net' => number_format((float) $row->inward - (float) $row->outward, 2, '.', '')]);

        return ['data' => $rows];
    }

    public function paymentModes(Request $request, BranchContext $context)
    {
        $from = $request->query('date_from', now()->startOfMonth()->toDateString());
        $to = $request->query('date_to', now()->toDateString());
        $rows = AccountTransaction::query()->where('branch_id', $context->id())->where('status', 'posted')->whereBetween('transaction_date', [$from, $to])
            ->selectRaw('payment_mode, direction, COUNT(*) AS count, SUM(amount) AS amount')->groupBy('payment_mode', 'direction')->orderBy('payment_mode')->get();

        return ['data' => $rows];
    }

    private function transactions(Request $request, BranchContext $context, string $direction)
    {
        $query = AccountTransaction::query()->with('party')->where('branch_id', $context->id())->where('direction', $direction)->orderByDesc('transaction_date')->orderByDesc('id');
        $query->when($request->query('status'), fn ($q, $value) => $q->where('status', $value));
        $query->when($request->query('payment_mode'), fn ($q, $value) => $q->where('payment_mode', $value));
        $query->when($request->query('date_from'), fn ($q, $value) => $q->whereDate('transaction_date', '>=', $value));
        $query->when($request->query('date_to'), fn ($q, $value) => $q->whereDate('transaction_date', '<=', $value));

        return AccountTransactionResource::collection($query->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    private function pettyBalance(int $branchId): string
    {
        return number_format((float) $this->pettyBalanceBefore($branchId, now()->addDay()->toDateString()), 2, '.', '');
    }

    private function pettyBalanceBefore(int $branchId, string $date): string
    {
        return (string) AccountTransaction::query()->where('branch_id', $branchId)->where('source_type', 'petty_cash')->where('status', 'posted')->whereDate('transaction_date', '<', $date)->selectRaw("COALESCE(SUM(CASE WHEN direction = 'inward' THEN amount ELSE -amount END), 0) AS balance")->value('balance');
    }
}
