<?php

namespace App\Services;

use App\Models\User;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\DB;

class ZaakiyContextService
{
    public function __construct(
        private readonly BranchContext $branchContext,
        private readonly OperationalDashboardService $dashboard,
    ) {}

    public function build(string $message, User $user): array
    {
        $branch = $this->branchContext->branch();
        $intent = $this->intent($message);
        $canViewAccounts = $user->hasPermission('accounts.view', $branch->id);
        $context = [
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'intent' => $intent['name'],
            'dashboard' => $this->dashboard->get($branch->id, $canViewAccounts),
        ];

        $financialIntent = in_array($intent['name'], ['today_inward_collection', 'outward_payments', 'tenant_outstanding', 'owner_payable'], true);
        if ($intent['route'] && (! $financialIntent || $canViewAccounts)) {
            $context['navigation'] = $intent['route'];
        }

        if ($intent['name'] === 'today_inward_collection' && $canViewAccounts) {
            $today = now()->toDateString();
            $context['today_inward_collection'] = DB::table('account_transactions')
                ->where('branch_id', $branch->id)->where('direction', 'inward')
                ->where('status', 'posted')->whereDate('transaction_date', $today)
                ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(amount), 0) as total_amount')
                ->first();
        }

        $query = mb_strtolower($message);
        if (preg_match('/owner|tenant|customer|property|agreement|lease|payment|outstanding|cheque|work order|maintenance/', $query)) {
            $context['matches'] = [
                'customers' => DB::table('customers')
                    ->where('branch_id', $branch->id)
                    ->whereNull('deleted_at')
                    ->where(function ($builder) use ($message) {
                        $builder->where('display_name', 'like', '%'.$message.'%')
                            ->orWhere('customer_code', 'like', '%'.$message.'%');
                    })
                    ->limit(8)->get(['id', 'customer_code', 'display_name', 'status']),
                'properties' => DB::table('properties')
                    ->where('branch_id', $branch->id)
                    ->whereNull('deleted_at')
                    ->where(function ($builder) use ($message) {
                        $builder->where('name', 'like', '%'.$message.'%')
                            ->orWhere('property_code', 'like', '%'.$message.'%')
                            ->orWhere('unit_number', 'like', '%'.$message.'%');
                    })
                    ->limit(8)->get(['id', 'property_code', 'name', 'unit_number', 'status']),
            ];
        }

        return $context;
    }

    private function intent(string $message): array
    {
        $query = mb_strtolower($message);

        return match (true) {
            preg_match('/who are you|what are you|about zaakiy/', $query) === 1 => [
                'name' => 'assistant_identity',
                'route' => null,
            ],
            preg_match('/today.*(inward|collection|received)|inward.*today/', $query) === 1 => [
                'name' => 'today_inward_collection',
                'route' => ['label' => 'Open Inward Receipts', 'url' => '/app/accounts/inward'],
            ],
            preg_match('/outward|paid out|payments made/', $query) === 1 => [
                'name' => 'outward_payments',
                'route' => ['label' => 'Open Outward Vouchers', 'url' => '/app/accounts/outward'],
            ],
            preg_match('/outstanding|receivable|due from tenant/', $query) === 1 => [
                'name' => 'tenant_outstanding',
                'route' => ['label' => 'Open Tenant Outstanding', 'url' => '/app/reports/tenant-outstanding'],
            ],
            preg_match('/payable|owner payment|due to owner/', $query) === 1 => [
                'name' => 'owner_payable',
                'route' => ['label' => 'Open Owner Payables', 'url' => '/app/reports/owner-payables'],
            ],
            preg_match('/expire|expiring|renew/', $query) === 1 => [
                'name' => 'agreement_expiry',
                'route' => ['label' => 'Open Agreement Expiry', 'url' => '/app/reports/agreement-expiry'],
            ],
            preg_match('/work order|maintenance|repair/', $query) === 1 => [
                'name' => 'work_orders',
                'route' => ['label' => 'Open Work Orders', 'url' => '/app/maintenance/work-orders'],
            ],
            preg_match('/property|properties|occupied|vacant|available/', $query) === 1 => [
                'name' => 'properties',
                'route' => ['label' => 'Open Properties', 'url' => '/app/properties'],
            ],
            preg_match('/owner/', $query) === 1 => [
                'name' => 'owners',
                'route' => ['label' => 'Open Owners', 'url' => '/app/customers/owners'],
            ],
            preg_match('/tenant/', $query) === 1 => [
                'name' => 'tenants',
                'route' => ['label' => 'Open Tenants', 'url' => '/app/customers/tenants'],
            ],
            preg_match('/agreement|lease/', $query) === 1 => [
                'name' => 'agreements',
                'route' => ['label' => 'Open Agreements', 'url' => '/app/tenant-agreements'],
            ],
            default => [
                'name' => 'general_erp_question',
                'route' => ['label' => 'Open Dashboard', 'url' => '/app/dashboard'],
            ],
        };
    }
}
