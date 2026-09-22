<?php

namespace App\Http\Requests\Api\V1\Agreements;

use Illuminate\Foundation\Http\FormRequest;

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
            'status' => ['nullable', 'in:draft,pending_approval,approved,commenced,expired,terminated'],
            'party_customer_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:created_at,-created_at,start_date,-start_date,end_date,-end_date,agreement_no,-agreement_no'],
        ];
    }
}
