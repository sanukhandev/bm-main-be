<?php

namespace App\Http\Requests\Api\V1\Agreements;

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
        return ['direction' => ['required', Rule::in(['inward', 'outward'])], 'category' => ['required', 'string', 'max:80'], 'particulars' => ['required', 'string', 'max:255'], 'amount' => ['required', 'numeric', 'min:0.01'], 'due_date' => ['required', 'date_format:Y-m-d'], 'payment_mode' => ['required', Rule::in(['cash', 'cheque', 'bank_transfer'])], 'terms' => ['nullable', 'string', 'max:5000']];
    }
}
