<?php

namespace App\Services\Zaakiy\Skills;

use App\Enums\ChequeStatus;
use App\Models\User;
use App\Services\OperationalDashboardService;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\DB;

class AccountsSkill implements ZaakiySkill
{
    public function __construct(private readonly OperationalDashboardService $dashboard) {}

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
        if (preg_match('/today.*(inward|collection|received)|inward.*today/i', $query) === 1) {
            $today = now()->toDateString();
            $row = DB::table('account_transactions')->where('branch_id', $branchId)->where('direction', 'inward')->where('status', 'posted')->whereDate('transaction_date', $today)->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(amount), 0) as total_amount')->first();
            $data['today_inward_collection'] = ['date' => $today, 'transaction_count' => (int) $row->transaction_count, 'total_amount' => number_format((float) $row->total_amount, 2, '.', '')];
        } elseif (preg_match('/outward|paid out/i', $query) === 1) {
            $data['today_outward'] = $this->todayTotal($branchId, 'outward');
            $navigation = ['label' => 'Open Outward Vouchers', 'url' => '/app/accounts/outward'];
        } elseif (preg_match('/cheque/i', $query) === 1) {
            $cheques = DB::table('account_transactions')->where('branch_id', $branchId)->where('status', 'posted')->where('payment_mode', 'cheque')->whereIn('cheque_status', [ChequeStatus::Received->value, ChequeStatus::Deposited->value]);
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
}
