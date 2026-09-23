<?php

namespace App\Http\Requests\Api\V1\Accounts;

use App\Enums\PaymentMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PostAgreementPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'installment_id' => ['nullable', 'integer', 'min:1'],
            'payment_mode' => ['required', Rule::enum(PaymentMode::class)],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'cheque_no' => ['required_if:payment_mode,cheque', 'nullable', 'string', 'max:100'],
            'cheque_date' => ['required_if:payment_mode,cheque', 'nullable', 'date_format:Y-m-d'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_reference' => ['required_if:payment_mode,bank_transfer', 'nullable', 'string', 'max:255'],
            'transfer_date' => ['required_if:payment_mode,bank_transfer', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
