<?php

namespace App\Http\Requests\Api\V1\Customers;

use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = trim((string) $this->input('customer_code', ''));
        $this->merge(['customer_code' => $code !== '' ? strtoupper($code) : null]);
    }

    public function rules(): array
    {
        return [
            'customer_code' => [
                'sometimes', 'nullable', 'string', 'max:64',
                Rule::unique('customers', 'customer_code')->where(fn ($query) => $query->where('branch_id', app(BranchContext::class)->id())),
            ],
            'customer_type' => ['required', Rule::in(['individual', 'organization'])],
            'display_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_registration_no' => ['nullable', 'string', 'max:100'],
            'identity_no' => ['nullable', 'string', 'max:100'],
            'company_registration_no' => ['nullable', 'string', 'max:100'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state_or_emirate' => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['required', 'in:owner,tenant', 'distinct'],
        ];
    }
}
