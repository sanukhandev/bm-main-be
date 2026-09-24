<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AuditLogResource;
use App\Models\AuditLog;
use App\Support\Branch\BranchContext;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, BranchContext $context)
    {
        $request->validate([
            'date_from' => 'nullable|date', 'date_to' => 'nullable|date|after_or_equal:date_from',
            'actor_user_id' => 'nullable|integer', 'action' => 'nullable|string|max:100',
            'entity_type' => 'nullable|string|max:100', 'entity_id' => 'nullable|integer',
            'search' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $query = AuditLog::query()->with(['actor', 'branch'])->where('branch_id', $context->id())
            ->when($request->query('date_from'), fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($request->query('actor_user_id'), fn ($q, $v) => $q->where('actor_user_id', $v))
            ->when($request->query('action'), fn ($q, $v) => $q->where('action', $v))
            ->when($request->query('entity_type'), fn ($q, $v) => $q->where('entity_type', $v))
            ->when($request->query('entity_id'), fn ($q, $v) => $q->where('entity_id', $v))
            ->when($request->query('search'), function ($q, $v) {
                $q->where(function ($inner) use ($v) {
                    $inner->where('action', 'like', "%{$v}%")->orWhere('entity_type', 'like', "%{$v}%")
                        ->orWhereHas('actor', fn ($actor) => $actor->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%"));
                });
            })->latest('created_at')->latest('id');

        return AuditLogResource::collection($query->paginate(min((int) $request->query('per_page', 25), 100)));
    }
}
