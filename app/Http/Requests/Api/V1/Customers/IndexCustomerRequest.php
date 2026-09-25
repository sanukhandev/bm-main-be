<?php

namespace App\Http\Requests\Api\V1\Customers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'archived'])],
            'customer_type' => ['nullable', Rule::in(['individual', 'organization'])],
            'role' => ['nullable', Rule::in(['owner', 'tenant', 'vendor'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in(['created_at', '-created_at', 'display_name', '-display_name', 'customer_code', '-customer_code'])],
        ];
    }
}
