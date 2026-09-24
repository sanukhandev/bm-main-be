<?php

namespace App\Services\Zaakiy\Skills;

use App\Enums\ChequeStatus;
use App\Models\User;
use App\Services\OperationalDashboardService;
use App\Services\Zaakiy\TimeRangeResolver;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\DB;

class AccountsSkill implements ZaakiySkill
{
    public function __construct(private readonly OperationalDashboardService $dashboard, private readonly TimeRangeResolver $timeRanges) {}

    public function matches(string $message): bool
    {
        return preg_match('/inward|collection|received|outward|paid out|payment|outstanding|receivable|payable|cheque|cash|petty/i', $message) === 1;
    }

    public function run(string $message, User $user, BranchContext $branch): array
    {
        $branchId = $branch->branch()->id;
        if (! $user->hasPermission('accounts.view', $branchId)) {
            return ['skill' => 'accounts', 'data' => ['restricted' => true]];
        }
        $query = mb_strtolower($message);
        $data = [];
        $navigation = ['label' => 'Open Accounts', 'url' => '/app/accounts/inward'];
        if (preg_match('/inward|collection|received/i', $query) === 1) {
            $data['collection'] = $this->collection($branchId, $message);
            if (preg_match('/compare|versus|vs|difference/i', $query) === 1) {
                $data['comparison'] = $this->collection($branchId, $message, true);
            }
            $navigation = ['label' => 'Open Inward Receipts', 'url' => '/app/accounts/inward'];
        } elseif (preg_match('/outward|paid out/i', $query) === 1) {
            $data['today_outward'] = $this->todayTotal($branchId, 'outward');
            $navigation = ['label' => 'Open Outward Vouchers', 'url' => '/app/accounts/outward'];
        } elseif (preg_match('/cheque/i', $query) === 1) {
            $statuses = preg_match('/bounce/i', $query) ? [ChequeStatus::Bounced->value] : (preg_match('/clear/i', $query) ? [ChequeStatus::Cleared->value] : [ChequeStatus::Received->value, ChequeStatus::Deposited->value]);
            $cheques = DB::table('account_transactions')->where('branch_id', $branchId)->where('status', 'posted')->where('payment_mode', 'cheque')->whereIn('cheque_status', $statuses);
            if ($range = $this->timeRanges->resolve($message)) {
                $cheques->whereBetween('transaction_date', [$range['from'], $range['to']]);
            }
            $data['pending_cheques'] = ['count' => (clone $cheques)->count(), 'value' => number_format((float) (clone $cheques)->sum('amount'), 2, '.', '')];
            $navigation = ['label' => 'Open Pending Cheques', 'url' => '/app/accounts/inward'];
        } else {
            $data['financial_attention'] = $this->dashboard->get($branchId, true)['financial_attention'];
            $navigation = ['label' => 'Open Financial Reports', 'url' => '/app/accounts/reports'];
        }

        return ['skill' => 'accounts', 'data' => $data, 'navigation' => $navigation];
    }

    private function todayTotal(int $branchId, string $direction): array
    {
        $row = DB::table('account_transactions')->where('branch_id', $branchId)->where('direction', $direction)->where('status', 'posted')->whereDate('transaction_date', now()->toDateString())->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(amount), 0) as total_amount')->first();

        return ['date' => now()->toDateString(), 'transaction_count' => (int) $row->transaction_count, 'total_amount' => number_format((float) $row->total_amount, 2, '.', '')];
    }

    private function collection(int $branchId, string $message, bool $comparison = false): array
    {
        $range = $comparison ? $this->timeRanges->comparison($message) : $this->timeRanges->resolve($message);
        $range ??= ['from' => now()->toDateString(), 'to' => now()->toDateString()];
        $query = DB::table('account_transactions')->where('branch_id', $branchId)->where('direction', 'inward')->where('status', 'posted')->whereBetween('transaction_date', [$range['from'], $range['to']]);
        $totals = (clone $query)->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(amount), 0) as total_amount')->first();
        $modes = (clone $query)->selectRaw('payment_mode, COALESCE(SUM(amount), 0) as amount')->groupBy('payment_mode')->pluck('amount', 'payment_mode');

        return ['period' => $range, 'transaction_count' => (int) $totals->transaction_count, 'total_amount' => number_format((float) $totals->total_amount, 2, '.', ''), 'by_payment_mode' => collect(['cash', 'cheque', 'bank_transfer'])->mapWithKeys(fn (string $mode) => [$mode => number_format((float) ($modes[$mode] ?? 0), 2, '.', '')])->all()];
    }
}
