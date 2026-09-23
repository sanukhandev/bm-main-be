<?php

namespace App\Http\Requests\Api\V1\Agreements;

use App\Enums\PaymentMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdditionalPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['direction' => ['required', Rule::in(['inward', 'outward'])], 'category' => ['required', 'string', 'max:80'], 'particulars' => ['required', 'string', 'max:255'], 'amount' => ['required', 'numeric', 'min:0.01'], 'due_date' => ['required', 'date_format:Y-m-d'], 'payment_mode' => ['required', Rule::enum(PaymentMode::class)], 'cheque_no' => ['required_if:payment_mode,cheque', 'nullable', 'string', 'max:100'], 'cheque_date' => ['required_if:payment_mode,cheque', 'nullable', 'date_format:Y-m-d'], 'bank_name' => ['nullable', 'string', 'max:255'], 'bank_reference' => ['required_if:payment_mode,bank_transfer', 'nullable', 'string', 'max:255'], 'transfer_date' => ['required_if:payment_mode,bank_transfer', 'nullable', 'date_format:Y-m-d'], 'terms' => ['nullable', 'string', 'max:5000']];
    }
}
