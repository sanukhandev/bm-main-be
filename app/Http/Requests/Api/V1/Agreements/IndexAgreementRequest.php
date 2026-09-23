<?php

namespace App\Http\Requests\Api\V1\Agreements;

use App\Enums\AgreementStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(AgreementStatus::values())],
            'party_customer_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:created_at,-created_at,start_date,-start_date,end_date,-end_date,agreement_no,-agreement_no'],
        ];
    }
}
