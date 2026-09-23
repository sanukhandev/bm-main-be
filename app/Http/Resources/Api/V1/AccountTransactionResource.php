<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'document_no' => $this->document_no,
            'direction' => $this->direction?->value ?? $this->direction,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'payment_mode' => $this->payment_mode?->value ?? $this->payment_mode,
            'amount' => $this->amount,
            'party' => new CustomerResource($this->whenLoaded('party')),
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'payment_sequence' => $this->payment_sequence,
            'remarks' => $this->remarks,
            'cheque_no' => $this->cheque_no,
            'cheque_date' => $this->cheque_date?->format('Y-m-d'),
            'bank_name' => $this->bank_name,
            'bank_reference' => $this->bank_reference,
            'transfer_date' => $this->transfer_date?->format('Y-m-d'),
            'status' => $this->status?->value ?? $this->status,
            'voided_by' => $this->voided_by,
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
