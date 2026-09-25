<?php

namespace App\Services;

use App\Models\User;
use App\Services\Reports\AgreementOccupancyQuery;
use App\Services\Reports\AgreementOutstandingQuery;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\DB;

class IntelligentReportService
{
    private const ACTIVE_AGREEMENTS = ['approved', 'commenced', 'on_hold'];

    public function __construct(
        private readonly AgreementOccupancyQuery $occupancy,
        private readonly AgreementOutstandingQuery $outstanding,
    )
    {
    }

    public function build(User $user, BranchContext $context, array $input): array
    {
        $period = IntelligentReportPeriod::resolve($input['period'] ?? null, $input['date_from'] ?? null, $input['date_to'] ?? null);
        $scope = $input['scope'] ?? 'branch';
        $overall = $scope === 'overall' && $user->hasGlobalRole('super_admin');
        if ($scope === 'overall' && ! $overall) {
            abort(403, 'Overall Business scope requires Super Admin access.');
        }
        $branchIds = $overall ? DB::table('branches')->where('status', 'active')->pluck('id')->all() : [$context->id()];
        $transactions = $this->transactions($branchIds, $period);
        $summary = $this->summary($branchIds, $period, $transactions);
        $trends = $this->trends($branchIds, $period);
        $findings = $this->findings($branchIds, $period, $summary);

        return [
            'data' => [
                'scope' => ['type' => $overall ? 'overall' : 'branch', 'branch_id' => $overall ? null : $context->id(), 'label' => $overall ? 'Overall Business' : $context->branch()->name],
                'period' => $period->toArray(), 'summary' => $summary, 'trends' => $trends,
                'findings' => $findings, 'branch_comparison' => $overall ? $this->branchComparison($branchIds, $period) : [],
                'accounting_note' => 'Operational profitability is based on financial and operational records captured within Baithul Madeena and is not a statutory accounting Profit & Loss statement.',
            ],
        ];
    }

    private function transactions(array $branchIds, IntelligentReportPeriod $period): array
    {
        $base = DB::table('account_transactions')->whereIn('branch_id', $branchIds)->where('status', 'posted')->whereBetween('transaction_date', [$period->from, $period->to]);
        $inward = (clone $base)->where('direction', 'inward')->sum('amount');
        $outward = (clone $base)->where('direction', 'outward')->sum('amount');
        $modes = (clone $base)->select('payment_mode')->selectRaw('SUM(amount) as amount')->groupBy('payment_mode')->pluck('amount', 'payment_mode');
        $bySource = (clone $base)->where('direction', 'outward')->select('source_type')->selectRaw('SUM(amount) as amount')->groupBy('source_type')->pluck('amount', 'source_type');

        return ['inward' => (float) $inward, 'outward' => (float) $outward, 'modes' => $modes->map(fn ($v) => (float) $v)->all(), 'by_source' => $bySource->map(fn ($v) => (float) $v)->all()];
    }

    private function summary(array $branchIds, IntelligentReportPeriod $period, array $transactions): array
    {
        $income = $transactions['inward'];
        $cost = $transactions['outward'];
        $profit = $income - $cost;
        $tenant = $this->outstanding->unpaid($branchIds, 'tenant');
        $owner = $this->outstanding->unpaid($branchIds, 'owner');
        $overdue = (clone $tenant)->whereDate('installments.due_date', '<', now()->toDateString());
        $cheques = DB::table('account_transactions')->whereIn('branch_id', $branchIds)->where('status', 'posted')->where('payment_mode', 'cheque')->whereBetween('transaction_date', [$period->from, $period->to]);
        $due = DB::table('tenant_agreement_installments')->whereIn('branch_id', $branchIds)->whereBetween('due_date', [$period->from, $period->to])->sum('amount');
        $collected = DB::table('tenant_agreement_installments')->whereIn('branch_id', $branchIds)->whereBetween('due_date', [$period->from, $period->to])->sum('paid_amount');
        $occupancy = $this->occupancy->summarize($branchIds, now()->toDateString());
        $properties = $occupancy['properties'];
        $occupied = $occupancy['occupied'];
        $maintenance = $transactions['by_source']['work_order_payment'] ?? 0;
        $petty = $transactions['by_source']['petty_cash'] ?? 0;

        return ['operating_income' => $this->money($income), 'operating_cost' => $this->money($cost), 'operational_profit_loss' => $this->money($profit), 'operating_margin_percent' => $income > 0 ? round($profit / $income * 100, 2) : null, 'total_inward' => $this->money($income), 'total_outward' => $this->money($cost), 'net_cash_movement' => $this->money($profit), 'tenant_receivables' => $this->money($tenant->sum(DB::raw('installments.amount - installments.paid_amount'))), 'owner_payables' => $this->money($owner->sum(DB::raw('installments.amount - installments.paid_amount'))), 'overdue_installments' => ['count' => $overdue->count(), 'amount' => $this->money($overdue->sum(DB::raw('installments.amount - installments.paid_amount')))], 'collection_efficiency_percent' => $due > 0 ? round($collected / $due * 100, 2) : null, 'pending_cheque_value' => $this->money((clone $cheques)->whereIn('cheque_status', ['received', 'deposited'])->sum('amount')), 'bounced_cheque_value' => $this->money((clone $cheques)->where('cheque_status', 'bounced')->sum('amount')), 'maintenance_expenditure' => $this->money($maintenance), 'petty_cash_expenditure' => $this->money($petty), 'occupied_properties' => $occupied, 'available_properties' => max(0, $properties - $occupied), 'occupancy_percent' => $properties > 0 ? round($occupied / $properties * 100, 2) : null, 'expiring_agreements' => $this->expiringCount($branchIds, $period)];
    }

    private function trends(array $branchIds, IntelligentReportPeriod $period): array
    {
        $rows = DB::table('account_transactions')->whereIn('branch_id', $branchIds)->where('status', 'posted')->whereBetween('transaction_date', [$period->from, $period->to])->select('transaction_date')->selectRaw("SUM(CASE WHEN direction = 'inward' THEN amount ELSE 0 END) as income, SUM(CASE WHEN direction = 'outward' THEN amount ELSE 0 END) as cost, SUM(CASE WHEN direction = 'inward' THEN amount ELSE -amount END) as result")->groupBy('transaction_date')->orderBy('transaction_date')->get();
        $grouped = [];
        foreach ($rows as $row) {
            $key = $period->granularity === 'monthly' ? substr($row->transaction_date, 0, 7) : $row->transaction_date;
            $grouped[$key]['income'] = ($grouped[$key]['income'] ?? 0) + (float) $row->income;
            $grouped[$key]['cost'] = ($grouped[$key]['cost'] ?? 0) + (float) $row->cost;
            $grouped[$key]['result'] = ($grouped[$key]['result'] ?? 0) + (float) $row->result;
        }

        return ['income_vs_cost' => array_map(fn ($key, $v) => ['period' => $key, 'income' => $this->money($v['income']), 'cost' => $this->money($v['cost'])], array_keys($grouped), array_values($grouped)), 'operational_result' => array_map(fn ($key, $v) => ['period' => $key, 'result' => $this->money($v['result'])], array_keys($grouped), array_values($grouped))];
    }

    private function findings(array $branchIds, IntelligentReportPeriod $period, array $summary): array
    {
        $findings = [];
        if ((float) $summary['overdue_installments']['amount'] > 0) {
            $findings[] = ['type' => 'overdue_receivable', 'severity' => (float) $summary['overdue_installments']['amount'] >= 10000 ? 'high' : 'medium', 'title' => 'Overdue tenant receivables', 'amount' => $summary['overdue_installments']['amount'], 'description' => 'Tenant installments remain unpaid after their due dates.', 'calculation_basis' => 'Tenant installments where due_date is before today and amount exceeds paid_amount.', 'navigation' => '/app/reports/tenant-outstanding'];
        }
        if ((float) $summary['bounced_cheque_value'] > 0) {
            $findings[] = ['type' => 'bounced_cheque_exposure', 'severity' => 'high', 'title' => 'Bounced cheque exposure', 'amount' => $summary['bounced_cheque_value'], 'description' => 'Posted cheque transactions in the selected period are marked bounced.', 'calculation_basis' => 'Posted account transactions with payment_mode cheque and cheque_status bounced.', 'navigation' => '/app/accounts/inward'];
        }
        if (($summary['expiring_agreements']['tenant'] ?? 0) > 0 && (float) $summary['tenant_receivables'] > 0) {
            $findings[] = ['type' => 'expiring_agreement_debt', 'severity' => 'medium', 'title' => 'Expiring agreements with receivables', 'amount' => $summary['tenant_receivables'], 'description' => 'Tenant agreements are approaching expiry while branch receivables remain outstanding.', 'calculation_basis' => 'Active tenant agreements ending inside the selected period and outstanding tenant installments.', 'navigation' => '/app/reports/agreement-expiry'];
        }

        return $findings;
    }

    private function expiringCount(array $branchIds, IntelligentReportPeriod $period): array
    {
        return ['owner' => DB::table('owner_agreements')->whereIn('branch_id', $branchIds)->whereBetween('end_date', [$period->from, $period->to])->whereIn('status', self::ACTIVE_AGREEMENTS)->count(), 'tenant' => DB::table('tenant_agreements')->whereIn('branch_id', $branchIds)->whereBetween('end_date', [$period->from, $period->to])->whereIn('status', self::ACTIVE_AGREEMENTS)->count()];
    }

    private function branchComparison(array $branchIds, IntelligentReportPeriod $period): array
    {
        return DB::table('branches')->whereIn('id', $branchIds)->where('status', 'active')->get(['id', 'name'])->map(function ($branch) use ($period) {
            $tx = DB::table('account_transactions')->where('branch_id', $branch->id)->where('status', 'posted')->whereBetween('transaction_date', [$period->from, $period->to]);
            $in = (float) (clone $tx)->where('direction', 'inward')->sum('amount');
            $out = (float) (clone $tx)->where('direction', 'outward')->sum('amount');

            return ['branch_id' => $branch->id, 'branch' => $branch->name, 'operating_income' => $this->money($in), 'operating_cost' => $this->money($out), 'operational_result' => $this->money($in - $out), 'margin' => $in > 0 ? round(($in - $out) / $in * 100, 2) : null, 'inward_collection' => $this->money($in), 'outward_payments' => $this->money($out)];
        })->values()->all();
    }

    private function money(float|int $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
