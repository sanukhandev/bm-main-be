<?php

namespace App\Http\Requests\Api\V1\Customers;

use App\Models\Customer;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $customer = $this->route('customer');
        $customerType = $this->input('customer_type', $customer instanceof Customer ? $customer->customer_type : null);
        $data = [];
        if ($this->has('customer_code')) {
            $data['customer_code'] = strtoupper(trim((string) $this->input('customer_code')));
        }
        if ($customerType === 'organization' && $this->has('identity_no')) {
            $data['identity_no'] = strtoupper(preg_replace('/\s+/', '', trim((string) $this->input('identity_no'))));
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $customer = $this->route('customer');
        $customerId = $customer instanceof Customer ? $customer->getKey() : $customer;

        return [
            'customer_code' => [
                'sometimes', 'string', 'max:64',
                Rule::unique('customers', 'customer_code')
                    ->ignore($customerId)
                    ->where(fn ($query) => $query->where('branch_id', app(BranchContext::class)->id())),
            ],
            'customer_type' => ['sometimes', Rule::in(['individual', 'organization'])],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_registration_no' => ['nullable', 'string', 'max:100'],
            'identity_no' => array_merge(['nullable', 'string', 'max:100'], ($this->input('customer_type', $customer instanceof Customer ? $customer->customer_type : null) === 'organization')
                ? ['regex:/^[A-Z0-9][A-Z0-9\/-]{4,49}$/i']
                : []),
            'company_registration_no' => ['nullable', 'string', 'max:100'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state_or_emirate' => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['required', 'in:owner,tenant,vendor', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return ['identity_no.regex' => 'Trade Licence number must be 5-50 letters, numbers, / or -.'];
    }
}
