<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportsService;
use App\Support\Branch\BranchContext;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function ownerAgreements(Request $request, BranchContext $context, ReportsService $service): array
    {
        $this->validateFilters($request);

        return $service->ownerAgreements($request, $context, $request->user()->hasPermission('accounts.view', $context->id()));
    }

    public function tenantAgreements(Request $request, BranchContext $context, ReportsService $service): array
    {
        $this->validateFilters($request);

        return $service->tenantAgreements($request, $context, $request->user()->hasPermission('accounts.view', $context->id()));
    }

    public function expiry(Request $request, BranchContext $context, ReportsService $service): array
    {
        $this->validateFilters($request, ['agreement_type' => 'nullable|in:owner,tenant,all']);

        return $service->expiry($request, $context);
    }

    public function tenantOutstanding(Request $request, BranchContext $context, ReportsService $service): array
    {
        $this->validateFilters($request);

        return $service->tenantOutstanding($request, $context);
    }

    public function ownerPayables(Request $request, BranchContext $context, ReportsService $service): array
    {
        $this->validateFilters($request);

        return $service->ownerPayables($request, $context);
    }

    public function inwardReceipts(Request $request, BranchContext $context, ReportsService $service): array
    {
        $this->validateFilters($request);

        return $service->transactions($request, $context, 'inward');
    }

    public function outwardVouchers(Request $request, BranchContext $context, ReportsService $service): array
    {
        $this->validateFilters($request);

        return $service->transactions($request, $context, 'outward');
    }

    public function dailyCashMovement(Request $request, BranchContext $context, ReportsService $service): array
    {
        $this->validateFilters($request);

        return $service->cashMovement($request, $context);
    }

    public function pettyCash(Request $request, BranchContext $context, ReportsService $service): array
    {
        $this->validateFilters($request);

        return $service->pettyCash($request, $context);
    }

    private function validateFilters(Request $request, array $extra = []): void
    {
        $request->validate($extra + ['date_from' => 'nullable|date', 'date_to' => 'nullable|date|after_or_equal:date_from', 'per_page' => 'nullable|integer|min:1|max:100']);
    }
}
