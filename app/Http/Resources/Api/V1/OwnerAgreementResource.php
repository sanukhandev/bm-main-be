<?php

namespace App\Http\Resources\Api\V1;

use App\Services\AgreementLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OwnerAgreementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'agreement_no' => $this->agreement_no,
            'file_no' => $this->file_no,
            'owner_customer_id' => $this->owner_customer_id,
            'owner' => new CustomerResource($this->whenLoaded('owner')),
            'properties' => PropertyResource::collection($this->whenLoaded('properties')),
            'installments' => $this->whenLoaded('installments', function () {
                $rows = $this->installments->map(function ($installment) {
                    $category = $installment->category ?: 'rent';
                    $particulars = $installment->particulars ?: $installment->notes ?: 'Installment '.$installment->installment_no.' payment';

                    return [
                        'id' => $installment->id, 'installment_no' => $installment->installment_no, 'due_date' => $installment->due_date?->format('Y-m-d'),
                        'amount' => $installment->amount, 'paid_amount' => $installment->paid_amount, 'balance' => number_format((float) $installment->amount - (float) $installment->paid_amount, 2, '.', ''),
                        'payment_mode' => $installment->payment_mode, 'category' => $category, 'particulars' => $particulars, 'transaction_reference' => $this->reference($category, $particulars, 'outward'), 'direction' => 'outward', 'status' => $installment->status, 'notes' => $installment->notes, 'is_extra' => false,
                        'receipt' => $this->receiptFor($installment->allocations, $installment->status),
                    ];
                });

                return $this->resource->relationLoaded('additionalPayments')
                    ? $rows->concat($this->additionalPayments->map(function ($line) {
                        $category = $line->category ?: 'others';
                        $particulars = $line->particulars ?: 'Additional payment';

                        return [
                            'id' => 'extra-'.$line->id, 'installment_no' => 'extra-'.$line->id, 'due_date' => $line->due_date?->format('Y-m-d'), 'amount' => $line->amount, 'paid_amount' => '0.00', 'balance' => $line->amount,
                            'payment_mode' => $line->payment_mode, 'category' => $category, 'particulars' => $particulars, 'transaction_reference' => $this->reference($category, $particulars, $line->direction ?: 'outward'), 'direction' => $line->direction ?: 'outward', 'status' => $line->status, 'notes' => $particulars.' | '.$category, 'is_extra' => true,
                            'receipt' => $this->receiptForAdditional($line),
                        ];
                    }))->values()
                    : $rows;
            }),
            'disputes' => $this->whenLoaded('disputes'),
            'additional_payments' => $this->whenLoaded('additionalPayments'),
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'total_amount' => $this->total_amount,
            'currency_code' => $this->currency_code,
            'payment_count' => $this->payment_count,
            'payment_frequency' => $this->payment_frequency,
            'payment_mode' => $this->payment_mode,
            'terms_text' => $this->terms_text,
            'notes' => $this->notes,
            'status' => $this->status,
            'available_actions' => app(AgreementLifecycleService::class)->availableActions('owner', $this->resource, (int) $this->branch_id),
            'renewed_from_agreement_id' => $this->renewed_from_agreement_id,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by_user_id' => $this->approved_by_user_id,
            'commenced_at' => $this->commenced_at?->toIso8601String(),
            'held_at' => $this->held_at?->toIso8601String(),
            'held_by_user_id' => $this->held_by_user_id,
            'hold_reason' => $this->hold_reason,
            'expired_at' => $this->expired_at?->toIso8601String(),
            'lock_version' => $this->lock_version,
            'terminated_at' => $this->terminated_at?->toIso8601String(),
            'termination_reason' => $this->termination_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function reference(?string $category, ?string $particulars, ?string $direction): string
    {
        return implode('/', [$this->owner?->customer_code ?? 'CUSTOMER', $this->agreement_no, strtoupper($direction ?: 'outward'), strtoupper($category ?: 'rent'), $particulars ?: '[particulars unavailable]']);
    }

    private function receiptFor($allocations, string $status): ?array
    {
        if ($status !== 'paid') {
            return null;
        }
        $transaction = $allocations->map->transaction->filter(fn ($transaction) => $transaction && $transaction->status?->value === 'posted')->sortByDesc('id')->first();

        return $transaction ? ['id' => $transaction->id, 'document_no' => $transaction->document_no, 'direction' => $transaction->direction?->value] : null;
    }

    private function receiptForAdditional($line): ?array
    {
        $transaction = $line->status === 'paid' ? $line->accountTransaction : null;

        return $transaction && $transaction->status?->value === 'posted'
            ? ['id' => $transaction->id, 'document_no' => $transaction->document_no, 'direction' => $transaction->direction?->value]
            : null;
    }
}
