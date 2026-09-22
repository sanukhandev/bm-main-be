<?php

namespace App\Actions;

use App\Exceptions\ApiException;
use App\Models\OwnerAgreement;
use App\Models\TenantAgreement;
use Illuminate\Support\Facades\DB;

class TransitionAgreement
{
    public function execute(string $type, int $id, int $branchId, string $to, ?string $reason, int $userId)
    {
        return DB::transaction(function () use ($type, $id, $branchId, $to, $reason, $userId) {
            $model = $type === 'owner' ? OwnerAgreement::class : TenantAgreement::class;
            $agreement = $model::query()->forBranch($branchId)->lockForUpdate()->findOrFail($id);
            $allowed = [
                'draft' => ['approved', 'terminated'],
                'approved' => ['commenced', 'terminated'],
                'commenced' => ['on_hold', 'terminated'],
                'on_hold' => ['commenced', 'terminated'],
            ];
            if (! in_array($to, $allowed[$agreement->status] ?? [], true)) {
                throw new ApiException('INVALID_STATUS_TRANSITION', "Cannot move agreement from {$agreement->status} to {$to}.", 422);
            }
            if (in_array($to, ['terminated', 'on_hold'], true) && blank($reason)) {
                throw new ApiException('TRANSITION_REASON_REQUIRED', 'A reason is required for this transition.', 422);
            }
            $from = $agreement->status;
            $agreement->forceFill([
                'status' => $to,
                'approved_at' => $to === 'approved' ? now() : $agreement->approved_at,
                'approved_by_user_id' => $to === 'approved' ? $userId : $agreement->approved_by_user_id,
                'commenced_at' => $to === 'commenced' ? now() : $agreement->commenced_at,
                'held_at' => $to === 'on_hold' ? now() : ($to === 'commenced' ? null : $agreement->held_at),
                'held_by_user_id' => $to === 'on_hold' ? $userId : ($to === 'commenced' ? null : $agreement->held_by_user_id),
                'hold_reason' => $to === 'on_hold' ? $reason : ($to === 'commenced' ? null : $agreement->hold_reason),
                'terminated_at' => $to === 'terminated' ? now() : $agreement->terminated_at,
                'terminated_by_user_id' => $to === 'terminated' ? $userId : $agreement->terminated_by_user_id,
                'termination_reason' => $to === 'terminated' ? $reason : $agreement->termination_reason,
            ])->save();
            $agreement->statusHistory()->create(['branch_id' => $branchId, 'from_status' => $from, 'to_status' => $to, 'action' => $to, 'changed_by_user_id' => $userId, 'reason' => $reason]);

            return $agreement;
        });
    }
}
