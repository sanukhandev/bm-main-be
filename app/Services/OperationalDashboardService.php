<?php

namespace App\Services;

use App\Enums\ChequeStatus;
use Illuminate\Support\Facades\DB;

class OperationalDashboardService
{
    private const ACTIVE_AGREEMENT_STATUSES = ['approved', 'commenced', 'on_hold'];

    private const OCCUPYING_TENANT_STATUSES = ['approved', 'commenced', 'on_hold'];

    public function get(int $branchId, bool $includeFinancial = true): array
    {
        $today = now()->toDateString();
        $expiryDate = now()->addDays(30)->toDateString();

        $owners = DB::table('customer_role_assignments as roles')
            ->join('customers', 'customers.id', '=', 'roles.customer_id')
            ->where('roles.branch_id', $branchId)->where('roles.role', 'owner')
            ->where('customers.status', 'active')->whereNull('customers.deleted_at')->count();
        $tenants = DB::table('customer_role_assignments as roles')
            ->join('customers', 'customers.id', '=', 'roles.customer_id')
            ->where('roles.branch_id', $branchId)->where('roles.role', 'tenant')
            ->where('customers.status', 'active')->whereNull('customers.deleted_at')->count();
        $properties = DB::table('properties')->where('branch_id', $branchId)->where('status', 'active')->whereNull('deleted_at')->count();
        $occupied = DB::table('tenant_agreement_properties as links')
            ->join('tenant_agreements as agreements', 'agreements.id', '=', 'links.tenant_agreement_id')
            ->where('links.branch_id', $branchId)->where('agreements.branch_id', $branchId)
            ->whereIn('agreements.status', self::OCCUPYING_TENANT_STATUSES)
            ->where('agreements.start_date', '<=', $today)->where('agreements.end_date', '>=', $today)
            ->whereNull('agreements.deleted_at')->distinct('links.property_id')->count('links.property_id');

        $ownerExpiring = $this->expiring('owner_agreements', 'owner_customer_id', $branchId, $today, $expiryDate, 'owner');
        $tenantExpiring = $this->expiring('tenant_agreements', 'tenant_customer_id', $branchId, $today, $expiryDate, 'tenant');
        $expiring = $ownerExpiring->concat($tenantExpiring)->sortBy('end_date')->take(10)->values()->all();

        $data = [
            'summary' => [
                'owners' => $owners,
                'tenants' => $tenants,
                'properties' => $properties,
                'owner_agreements_active' => $this->agreementCount('owner_agreements', $branchId),
                'tenant_agreements_active' => $this->agreementCount('tenant_agreements', $branchId),
            ],
            'occupancy' => [
                'available_properties' => max(0, $properties - $occupied),
                'occupied_properties' => $occupied,
            ],
            'agreements' => [
                'owner_expiring_30_days' => $ownerExpiring->count(),
                'tenant_expiring_30_days' => $tenantExpiring->count(),
            ],
            'financial_attention' => $includeFinancial ? $this->financialAttention($branchId, $today) : null,
            'maintenance' => $this->maintenance($branchId),
            'expiring_agreements' => $expiring,
        ];

        return $data + [
            'total_owners' => $owners,
            'total_tenants' => $tenants,
            'total_properties' => $properties,
            'total_owner_agreements' => $data['summary']['owner_agreements_active'],
            'total_tenant_agreements' => $data['summary']['tenant_agreements_active'],
            'expiring_soon_agreements' => $tenantExpiring->count(),
        ];
    }

    private function agreementCount(string $table, int $branchId): int
    {
        return DB::table($table)->where('branch_id', $branchId)->whereIn('status', self::ACTIVE_AGREEMENT_STATUSES)->whereNull('deleted_at')->count();
    }

    private function expiring(string $table, string $customerKey, int $branchId, string $today, string $expiryDate, string $type)
    {
        return DB::table($table.' as agreements')->join('customers', 'customers.id', '=', 'agreements.'.$customerKey)
            ->where('agreements.branch_id', $branchId)->whereIn('agreements.status', self::ACTIVE_AGREEMENT_STATUSES)
            ->whereBetween('agreements.end_date', [$today, $expiryDate])->whereNull('agreements.deleted_at')->whereNull('customers.deleted_at')
            ->orderBy('agreements.end_date')->limit(10)->get([
                'agreements.id', 'agreements.agreement_no', 'agreements.end_date', 'agreements.status',
                DB::raw("'{$type}' as agreement_type"), 'customers.display_name as customer_name',
            ])->map(fn ($row) => [
                'id' => $row->id,
                'agreement_no' => $row->agreement_no,
                'agreement_type' => $row->agreement_type,
                'customer' => $row->customer_name,
                'end_date' => $row->end_date,
                'days_remaining' => max(0, now()->startOfDay()->diffInDays($row->end_date, false)),
                'status' => $row->status,
            ]);
    }

    private function financialAttention(int $branchId, string $today): array
    {
        $outstanding = fn (string $table) => number_format((float) DB::table($table)->where('branch_id', $branchId)
            ->whereIn('status', ['pending', 'partially_paid'])->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'), 2, '.', '');
        $overdue = DB::table('tenant_agreement_installments')->where('branch_id', $branchId)->where('due_date', '<', $today)
            ->whereIn('status', ['pending', 'partially_paid'])->whereColumn('paid_amount', '<', 'amount');
        $cheques = DB::table('account_transactions')->where('branch_id', $branchId)->where('status', 'posted')->where('payment_mode', 'cheque')
            ->whereIn('cheque_status', [ChequeStatus::Received->value, ChequeStatus::Deposited->value]);

        return [
            'tenant_receivables' => $outstanding('tenant_agreement_installments'),
            'owner_payables' => $outstanding('owner_agreement_installments'),
            'overdue_tenant_installments' => ['count' => $overdue->count(), 'amount' => number_format((float) $overdue->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'), 2, '.', '')],
            'pending_cheques' => ['count' => (clone $cheques)->count(), 'value' => number_format((float) (clone $cheques)->sum('amount'), 2, '.', '')],
        ];
    }

    private function maintenance(int $branchId): array
    {
        $rows = DB::table('work_orders as orders')->join('properties', 'properties.id', '=', 'orders.property_id')
            ->leftJoin('customers as vendors', function ($join) use ($branchId): void {
                $join->on('vendors.id', '=', 'orders.vendor_id')->where('vendors.branch_id', $branchId);
            })->where('orders.branch_id', $branchId)
            ->whereNotIn('orders.status', ['completed', 'cancelled'])->orderByDesc('orders.created_at')->limit(5)->get([
                'orders.id', 'orders.work_order_no', 'orders.title', 'orders.priority', 'orders.status', 'orders.created_at',
                'properties.name as property_name', 'vendors.display_name as vendor_name',
            ]);

        return ['open_work_orders' => DB::table('work_orders')->where('branch_id', $branchId)->whereNotIn('status', ['completed', 'cancelled'])->count(), 'items' => $rows];
    }
}
