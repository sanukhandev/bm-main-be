<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IntelligentReportRequest;
use App\Services\IntelligentReportPdfService;
use App\Services\IntelligentReportService;
use App\Support\Branch\BranchContext;
use Symfony\Component\HttpFoundation\Response;

class IntelligentReportController extends Controller
{
    public function show(IntelligentReportRequest $request, BranchContext $context, IntelligentReportService $service): array
    {
        return $service->build($request->user(), $context, $request->validated());
    }

    public function pdf(IntelligentReportRequest $request, BranchContext $context, IntelligentReportService $service, IntelligentReportPdfService $pdf): Response
    {
        $report = $service->build($request->user(), $context, $request->validated());

        return $pdf->download($report['data'], $request->user());
    }
}
