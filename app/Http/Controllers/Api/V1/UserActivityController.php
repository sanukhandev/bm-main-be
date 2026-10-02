<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreUserActivityRequest;
use App\Services\AuditService;
use App\Support\Branch\BranchContext;
use Illuminate\Http\JsonResponse;

class UserActivityController extends Controller
{
    public function store(StoreUserActivityRequest $request, BranchContext $context, AuditService $audit): JsonResponse
    {
        $data = $request->validated();
        $audit->recordAsync(
            action: 'activity.'.$data['action'],
            branchId: $context->id(),
            actorId: $request->user()->getAuthIdentifier(),
            metadata: [
                'page' => $data['page'],
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => $data['entity_id'] ?? null,
                ...($data['metadata'] ?? []),
                'source' => 'frontend',
            ],
        );

        return response()->json(['queued' => true], 202);
    }
}
