<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OperationalDashboardService;
use App\Support\Branch\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function metrics(Request $request, BranchContext $branchContext, OperationalDashboardService $service): JsonResponse
    {
        return response()->json(['data' => $service->get($branchContext->id(), $request->user()->hasPermission('accounts.view', $branchContext->id()))]);
    }

    public function operational(Request $request, BranchContext $branchContext, OperationalDashboardService $service): JsonResponse
    {
        return response()->json(['data' => $service->get($branchContext->id(), $request->user()->hasPermission('accounts.view', $branchContext->id()))]);
    }
}
