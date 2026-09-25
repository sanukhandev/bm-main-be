<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

class AgreementOccupancyQuery
{
    private const BLOCKING_TENANT_STATUSES = ['approved', 'commenced', 'on_hold'];

    public function summarize(array $branchIds, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();
        $properties = DB::table('properties')->whereIn('branch_id', $branchIds)->where('status', 'active')->whereNull('deleted_at')->count();
        $occupied = DB::table('tenant_agreement_properties as links')
            ->join('tenant_agreements as agreements', 'agreements.id', '=', 'links.tenant_agreement_id')
            ->whereIn('links.branch_id', $branchIds)
            ->whereColumn('agreements.branch_id', 'links.branch_id')
            ->whereIn('agreements.status', self::BLOCKING_TENANT_STATUSES)
            ->whereDate('agreements.start_date', '<=', $asOf)
            ->whereDate('agreements.end_date', '>=', $asOf)
            ->whereNull('agreements.deleted_at')
            ->distinct()
            ->count('links.property_id');

        return ['properties' => $properties, 'occupied' => $occupied, 'available' => max(0, $properties - $occupied)];
    }
}
