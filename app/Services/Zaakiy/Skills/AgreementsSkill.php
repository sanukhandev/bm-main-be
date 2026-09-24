<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\User;
use App\Services\OperationalDashboardService;
use App\Services\Zaakiy\TimeRangeResolver;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\DB;

class AgreementsSkill implements ZaakiySkill
{
    public function __construct(private readonly OperationalDashboardService $dashboard, private readonly TimeRangeResolver $timeRanges) {}

    public function matches(string $message): bool
    {
        return preg_match('/agreement|agreements|lease|expir|renew|commenc|tenant contract|owner contract/i', $message) === 1;
    }

    public function run(string $message, User $user, BranchContext $branch): array
    {
        if (preg_match('/expire|expiring/', $message) === 1 && preg_match('/outstanding|owe|owed|due/', $message) === 1 && $user->hasPermission('accounts.view', $branch->branch()->id)) {
            return [
                'skill' => 'agreements',
                'data' => ['expiring_with_outstanding' => $this->expiringWithOutstanding($branch->branch()->id, $message)],
                'navigation' => ['label' => 'Open Tenant Outstanding', 'url' => '/app/reports/tenant-outstanding'],
            ];
        }
        $data = $this->dashboard->get($branch->branch()->id, false);

        return ['skill' => 'agreements', 'data' => ['active_owner_agreements' => $data['summary']['owner_agreements_active'], 'active_tenant_agreements' => $data['summary']['tenant_agreements_active'], 'owner_expiring_30_days' => $data['agreements']['owner_expiring_30_days'], 'tenant_expiring_30_days' => $data['agreements']['tenant_expiring_30_days'], 'expiring_agreements' => $data['expiring_agreements']], 'navigation' => ['label' => 'Open Agreement Expiry', 'url' => '/app/reports/agreement-expiry']];
    }

    private function expiringWithOutstanding(int $branchId, string $message): array
    {
        $range = $this->timeRanges->resolve($message) ?? ['from' => now()->toDateString(), 'to' => now()->addDays(30)->toDateString()];

        return DB::table('tenant_agreements as agreements')
            ->join('customers', 'customers.id', '=', 'agreements.tenant_customer_id')
            ->leftJoin('tenant_agreement_installments as installments', function ($join): void {
                $join->on('installments.tenant_agreement_id', '=', 'agreements.id')->on('installments.branch_id', '=', 'agreements.branch_id');
            })
            ->where('agreements.branch_id', $branchId)
            ->whereIn('agreements.status', ['approved', 'commenced', 'on_hold'])
            ->whereBetween('agreements.end_date', [$range['from'], $range['to']])
            ->whereNull('agreements.deleted_at')->whereNull('customers.deleted_at')
            ->groupBy('agreements.id', 'agreements.agreement_no', 'customers.display_name', 'agreements.end_date', 'agreements.status')
            ->havingRaw('COALESCE(SUM(installments.amount - installments.paid_amount), 0) > 0')
            ->orderBy('agreements.end_date')->limit(10)
            ->get(['agreements.id', 'agreements.agreement_no', 'customers.display_name as tenant', 'agreements.end_date', 'agreements.status'])
            ->map(fn ($row) => [
                'entity_type' => 'tenant_agreement', 'entity_id' => $row->id, 'reference' => $row->agreement_no,
                'label' => $row->tenant, 'fields' => ['end_date' => $row->end_date, 'status' => $row->status],
            ])->all();
    }
}
