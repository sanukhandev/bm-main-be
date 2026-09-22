<?php

namespace App\Http\Requests\Api\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBillingPaymentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['paid', 'defaulted'])]];
    }
}
