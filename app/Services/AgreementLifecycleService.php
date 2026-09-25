<?php

namespace App\Services;

use App\Enums\AgreementStatus;
use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\OwnerAgreement;
use App\Models\TenantAgreement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Support\DecimalAmount;

class AgreementLifecycleService
{
    private const TRANSITIONS = [
        'draft' => ['pending_approval', 'cancelled'],
        'pending_approval' => ['draft', 'approved', 'cancelled'],
        'approved' => ['commenced', 'cancelled'],
        'commenced' => ['on_hold', 'expired', 'terminated'],
        'on_hold' => ['commenced', 'expired', 'terminated'],
        'expired' => [],
        'terminated' => [],
        'cancelled' => [],
    ];

    public function transition(string $type, int $id, int $branchId, string $to, ?string $reason, ?int $userId): Model
    {
        return DB::transaction(function () use ($type, $id, $branchId, $to, $reason, $userId) {
            $agreement = $this->query($type)->forBranch($branchId)->lockForUpdate()->findOrFail($id);
            $this->assertTransition($agreement, $to, $reason, $branchId);

            if ($to === AgreementStatus::PendingApproval->value) {
                $this->assertComplete($type, $agreement, $branchId);
            }
            if ($type === 'tenant' && in_array($to, [AgreementStatus::Approved->value, AgreementStatus::Commenced->value], true)) {
                $this->assertComplete($type, $agreement, $branchId);
            }
            if ($to === AgreementStatus::Commenced->value && $this->today($branchId)->lt($this->agreementDate($agreement->start_date, $branchId))) {
                throw new ApiException('AGREEMENT_CANNOT_COMMENCE', 'An agreement cannot commence before its start date.', 409);
            }
            if ($to === AgreementStatus::Commenced->value && $this->today($branchId)->gt($this->agreementDate($agreement->end_date, $branchId))) {
                throw new ApiException('AGREEMENT_CANNOT_COMMENCE', 'An agreement cannot commence after its end date.', 409);
            }
            if ($to === AgreementStatus::Cancelled->value && $agreement->status === AgreementStatus::Approved->value && $this->today($branchId)->gte($this->agreementDate($agreement->start_date, $branchId))) {
                throw new ApiException('INVALID_STATUS_TRANSITION', 'An approved agreement cannot be cancelled after its start date.', 409);
            }
            if ($to === AgreementStatus::Commenced->value && $agreement->status === AgreementStatus::OnHold->value && $this->today($branchId)->gt($this->agreementDate($agreement->end_date, $branchId))) {
                throw new ApiException('INVALID_STATUS_TRANSITION', 'An expired agreement cannot be resumed.', 409);
            }
            if (in_array($to, [AgreementStatus::Expired->value], true) && $this->today($branchId)->lte($this->agreementDate($agreement->end_date, $branchId))) {
                throw new ApiException('INVALID_STATUS_TRANSITION', 'The agreement period has not ended.', 409);
            }

            $from = $agreement->status;
            $before = ['status' => $from, 'start_date' => $agreement->start_date?->format('Y-m-d'), 'end_date' => $agreement->end_date?->format('Y-m-d')];
            $agreement->forceFill($this->transitionAttributes($to, $reason, $userId))->save();
            $this->history($type, $agreement, $from, $to, $this->actionFor($to), $reason, $userId);
            app(AuditService::class)->record($type.'_agreement.'.$this->actionFor($to), $agreement, $before, ['status' => $agreement->status, 'start_date' => $agreement->start_date?->format('Y-m-d'), 'end_date' => $agreement->end_date?->format('Y-m-d')], ['reason' => $reason, 'actor_type' => $userId ? 'user' : 'system'], $branchId, $userId);

            return $agreement;
        });
    }

    public function extend(string $type, int $id, int $branchId, string $newEndDate, string $reason, ?int $userId): Model
    {
        return DB::transaction(function () use ($type, $id, $branchId, $newEndDate, $reason, $userId) {
            $agreement = $this->query($type)->forBranch($branchId)->lockForUpdate()->findOrFail($id);
            $newEnd = $this->agreementDate($newEndDate, $branchId);
            $oldEnd = $this->agreementDate($agreement->end_date, $branchId);
            if ($newEnd->lte($oldEnd)) {
                throw new ApiException('AGREEMENT_CANNOT_EXTEND', 'The new end date must be later than the current end date.', 422);
            }
            if (blank($reason)) {
                throw new ApiException('TRANSITION_REASON_REQUIRED', 'A reason is required to extend an agreement.', 422);
            }
            if (in_array($agreement->status, [AgreementStatus::Terminated->value, AgreementStatus::Cancelled->value], true)) {
                throw new ApiException('AGREEMENT_CANNOT_EXTEND', 'This agreement cannot be extended.', 409);
            }

            if ($type === 'tenant') {
                $properties = $agreement->properties()->get()->map(fn ($property) => [
                    'property_id' => $property->id,
                    'source_owner_agreement_id' => $property->pivot->source_owner_agreement_id,
                ])->all();
                $this->availability($branchId)->lockProperties($branchId, array_column($properties, 'property_id'));
                $this->availability($branchId)->assertOwnerCoverage($branchId, $properties, CarbonImmutable::parse($agreement->start_date), $newEnd);
                $this->availability($branchId)->assertAvailable($branchId, array_column($properties, 'property_id'), CarbonImmutable::parse($agreement->start_date), $newEnd, $agreement->id);
            }

            $from = $agreement->status;
            $to = $from;
            if ($from === AgreementStatus::Expired->value) {
                $today = $this->today($branchId);
                if ($today->gt($newEnd)) {
                    throw new ApiException('AGREEMENT_CANNOT_EXTEND', 'The new end date must reach the current business date.', 409);
                }
                $to = $today->lt($this->agreementDate($agreement->start_date, $branchId)) ? AgreementStatus::Approved->value : AgreementStatus::Commenced->value;
            }
            $agreement->forceFill(['end_date' => $newEnd->toDateString(), 'status' => $to, 'expired_at' => null])->save();
            $agreement->increment('lock_version');
            $agreement->refresh();
            $this->history($type, $agreement, $from, $to, 'extend', $reason, $userId, ['old_end_date' => $oldEnd->toDateString(), 'new_end_date' => $newEnd->toDateString()]);
            app(AuditService::class)->record($type.'_agreement.extended', $agreement, ['end_date' => $oldEnd->toDateString()], ['end_date' => $newEnd->toDateString(), 'status' => $agreement->status], ['old_end_date' => $oldEnd->toDateString(), 'new_end_date' => $newEnd->toDateString(), 'reason' => $reason], $branchId, $userId);

            return $agreement;
        });
    }

    public function renew(string $type, int $id, int $branchId, array $data, Branch $branch, DocumentNumberGenerator $numbers, AgreementScheduleService $schedules, ?int $userId): Model
    {
        return DB::transaction(function () use ($type, $id, $branchId, $data, $branch, $numbers, $schedules, $userId) {
            $source = $this->query($type)->forBranch($branchId)->lockForUpdate()->findOrFail($id);
            $start = $this->agreementDate($data['start_date'], $branchId);
            $end = $this->agreementDate($data['end_date'], $branchId);
            if ($end->lt($start) || $start->lte($this->agreementDate($source->end_date, $branchId))) {
                throw new ApiException('AGREEMENT_CANNOT_RENEW', 'A renewal must start after the original agreement period.', 422);
            }

            $attributes = $source->only(['owner_customer_id', 'tenant_customer_id', 'total_amount', 'currency_code', 'payment_count', 'payment_frequency', 'payment_mode', 'terms_text', 'notes']);
            $attributes['start_date'] = $start->toDateString();
            $attributes['end_date'] = $end->toDateString();
            $attributes['agreement_no'] = $numbers->next($branch, $type === 'owner' ? 'OWNER_AGREEMENT' : 'TENANT_AGREEMENT', (int) $start->format('Y'));
            unset($attributes['tenant_customer_id'], $attributes['owner_customer_id']);
            $new = $this->query($type)->getModel()->newInstance($attributes + ($type === 'owner' ? ['owner_customer_id' => $source->owner_customer_id] : ['tenant_customer_id' => $source->tenant_customer_id]));
            $new->forceFill(['branch_id' => $branchId, 'status' => AgreementStatus::Draft->value, 'renewed_from_agreement_id' => $source->id])->save();

            if ($type === 'owner') {
                $properties = $source->properties()->pluck('properties.id')->all();
                $new->properties()->attach($properties, ['branch_id' => $branchId, 'owner_customer_id' => $source->owner_customer_id]);
            } else {
                $properties = $source->properties()->get()->map(fn ($property) => ['property_id' => $property->id, 'source_owner_agreement_id' => $property->pivot->source_owner_agreement_id])->all();
                $this->availability($branchId)->lockProperties($branchId, array_column($properties, 'property_id'));
                $this->availability($branchId)->assertOwnerCoverage($branchId, $properties, $start, $end);
                $this->availability($branchId)->assertAvailable($branchId, array_column($properties, 'property_id'), $start, $end);
                foreach ($properties as $property) {
                    $new->properties()->attach($property['property_id'], ['branch_id' => $branchId, 'source_owner_agreement_id' => $property['source_owner_agreement_id']]);
                }
            }
            $schedules->create($type, $new->id, $branchId, $new->start_date->format('Y-m-d'), $new->payment_count, $new->total_amount, $new->payment_frequency ?: 'monthly', $new->payment_mode);
            $this->history($type, $source, $source->status, $source->status, 'renew', 'Renewed as agreement '.$new->agreement_no, $userId, ['renewed_agreement_id' => $new->id]);
            $this->history($type, $new, null, AgreementStatus::Draft->value, 'renew', null, $userId, ['renewed_from_agreement_id' => $source->id]);
            app(AuditService::class)->record($type.'_agreement.renewed', $source, null, null, ['source_agreement_id' => $source->id, 'new_agreement_id' => $new->id, 'new_agreement_number' => $new->agreement_no], $branchId, $userId);
            app(AuditService::class)->record($type.'_agreement.created', $new, null, ['status' => $new->status, 'agreement_no' => $new->agreement_no], ['renewed_from_agreement_id' => $source->id], $branchId, $userId);

            return $new;
        });
    }

    public function availableActions(string $type, Model $agreement, int $branchId): array
    {
        return match ($agreement->status) {
            'draft' => ['submit', 'cancel'],
            'pending_approval' => ['approve', 'cancel'],
            'approved' => $this->today($branchId)->gte($this->agreementDate($agreement->start_date, $branchId)) ? ['commence', 'cancel'] : ['cancel'],
            'commenced' => ['hold', 'terminate', 'extend'],
            'on_hold' => ['resume', 'terminate', 'extend'],
            'expired' => ['extend', 'renew'],
            'terminated' => ['renew'],
            default => [],
        };
    }

    private function assertTransition(Model $agreement, string $to, ?string $reason, int $branchId): void
    {
        if (! in_array($to, AgreementStatus::values(), true) || ! in_array($to, self::TRANSITIONS[$agreement->status] ?? [], true)) {
            throw new ApiException('INVALID_STATUS_TRANSITION', "Cannot move agreement from {$agreement->status} to {$to}.", 409);
        }
        if (in_array($to, ['on_hold', 'terminated', 'cancelled'], true) && $agreement->status !== 'draft' && blank($reason)) {
            throw new ApiException('TRANSITION_REASON_REQUIRED', 'A reason is required for this lifecycle action.', 422);
        }
    }

    private function assertComplete(string $type, Model $agreement, int $branchId): void
    {
        if (($type === 'tenant' && ! $agreement->tenant) || ($type === 'owner' && ! $agreement->owner) || $agreement->properties()->count() === 0) {
            throw new ApiException('AGREEMENT_INCOMPLETE', 'The agreement must have an active party and at least one property.', 422);
        }
        $installments = DB::table($type === 'owner' ? 'owner_agreement_installments' : 'tenant_agreement_installments')->where($type === 'owner' ? 'owner_agreement_id' : 'tenant_agreement_id', $agreement->id)->get(['amount']);
        if ($installments->count() !== (int) $agreement->payment_count || DecimalAmount::toCents($installments->sum(fn ($row) => (string) $row->amount)) !== DecimalAmount::toCents((string) $agreement->total_amount)) {
            throw new ApiException('AGREEMENT_SCHEDULE_MISMATCH', 'The installment schedule does not reconcile with the agreement total.', 422);
        }
        if ($type === 'tenant') {
            $properties = $agreement->properties()->get()->map(fn ($property) => ['property_id' => $property->id, 'source_owner_agreement_id' => $property->pivot->source_owner_agreement_id])->all();
            $start = CarbonImmutable::parse($agreement->start_date);
            $end = CarbonImmutable::parse($agreement->end_date);
            $this->availability($branchId)->lockProperties($branchId, array_column($properties, 'property_id'));
            $this->availability($branchId)->assertOwnerCoverage($branchId, $properties, $start, $end);
            $this->availability($branchId)->assertAvailable($branchId, array_column($properties, 'property_id'), $start, $end, $agreement->id);
        }
    }

    private function transitionAttributes(string $to, ?string $reason, ?int $userId): array
    {
        return match ($to) {
            'pending_approval' => ['status' => $to, 'submitted_at' => now()],
            'approved' => ['status' => $to, 'approved_at' => now(), 'approved_by_user_id' => $userId],
            'commenced' => ['status' => $to, 'commenced_at' => now(), 'held_at' => null, 'held_by_user_id' => null, 'hold_reason' => null],
            'on_hold' => ['status' => $to, 'held_at' => now(), 'held_by_user_id' => $userId, 'hold_reason' => $reason],
            'expired' => ['status' => $to, 'expired_at' => now()],
            'terminated' => ['status' => $to, 'terminated_at' => now(), 'terminated_by_user_id' => $userId, 'termination_reason' => $reason],
            'cancelled' => ['status' => $to],
            default => ['status' => $to],
        };
    }

    private function history(string $type, Model $agreement, ?string $from, string $to, string $action, ?string $reason, ?int $userId, ?array $metadata = null): void
    {
        $agreement->statusHistory()->create(['branch_id' => $agreement->branch_id, 'from_status' => $from, 'to_status' => $to, 'action' => $action, 'changed_by_user_id' => $userId, 'reason' => $reason, 'metadata_json' => $metadata]);
    }

    private function query(string $type)
    {
        return $type === 'owner' ? OwnerAgreement::query() : TenantAgreement::query();
    }

    private function availability(int $branchId): PropertyAvailabilityService
    {
        return app(PropertyAvailabilityService::class);
    }

    private function today(int $branchId): CarbonImmutable
    {
        $branch = Branch::query()->findOrFail($branchId);

        return CarbonImmutable::today($branch->timezone ?: config('app.timezone'));
    }

    private function agreementDate($date, int $branchId): CarbonImmutable
    {
        $branch = Branch::query()->findOrFail($branchId);
        $dateValue = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;

        return CarbonImmutable::parse($dateValue, $branch->timezone ?: config('app.timezone'))->startOfDay();
    }

    private function actionFor(string $to): string
    {
        return match ($to) {
            'pending_approval' => 'submit', 'approved' => 'approve', 'commenced' => 'commence', 'on_hold' => 'hold', 'expired' => 'expire', 'terminated' => 'terminate', 'cancelled' => 'cancel', default => $to,
        };
    }

}
