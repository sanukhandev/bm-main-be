<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Branch\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function metrics(BranchContext $branchContext): JsonResponse
    {
        $branchId = $branchContext->id();

        return response()->json([
            'data' => [
                'total_owners' => DB::table('customer_role_assignments')->where('branch_id', $branchId)->where('role', 'owner')->count(),
                'total_tenants' => DB::table('customer_role_assignments')->where('branch_id', $branchId)->where('role', 'tenant')->count(),
                'total_properties' => DB::table('properties')->where('branch_id', $branchId)->where('status', 'active')->count(),
                'total_owner_agreements' => DB::table('owner_agreements')->where('branch_id', $branchId)->whereIn('status', ['approved', 'commenced'])->count(),
                'total_tenant_agreements' => DB::table('tenant_agreements')->where('branch_id', $branchId)->whereIn('status', ['approved', 'commenced'])->count(),
            ],
        ]);
    }
}
