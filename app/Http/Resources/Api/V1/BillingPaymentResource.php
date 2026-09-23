<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class BillingPaymentResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'direction' => $this->direction, 'particulars' => $this->particulars, 'amount' => $this->amount, 'due_date' => $this->due_date?->format('Y-m-d'), 'payment_mode' => $this->payment_mode, 'cheque_no' => $this->cheque_no, 'cheque_date' => $this->cheque_date?->format('Y-m-d'), 'bank_name' => $this->bank_name, 'bank_reference' => $this->bank_reference, 'transfer_date' => $this->transfer_date?->format('Y-m-d'), 'status' => $this->status, 'terms' => $this->terms, 'receipt' => $this->receipt(), 'created_at' => $this->created_at?->toIso8601String()];
    }

    private function receipt(): ?array
    {
        $transaction = $this->status === 'paid' ? $this->accountTransaction : null;

        return $transaction && $transaction->status?->value === 'posted'
            ? ['id' => $transaction->id, 'document_no' => $transaction->document_no, 'direction' => $transaction->direction?->value]
            : null;
    }
}
