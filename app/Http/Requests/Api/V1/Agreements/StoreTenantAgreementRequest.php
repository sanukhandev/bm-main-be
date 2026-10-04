<?php

namespace App\Http\Requests\Api\V1\Agreements;

use App\Http\Requests\Api\V1\Agreements\Concerns\ValidatesAgreementInstallments;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantAgreementRequest extends FormRequest
{
    use ValidatesAgreementInstallments;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = app(BranchContext::class)->id();

        return [
            'agreement_no' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('tenant_agreements', 'agreement_no')->where(fn ($query) => $query->where('branch_id', $branchId))],
            'tenant_customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('branch_id', $branchId)->where('status', 'active'))],
            'properties' => ['required', 'array', 'min:1'],
            'properties.*.property_id' => ['required', 'integer', 'distinct', Rule::exists('properties', 'id')->where(fn ($query) => $query->where('branch_id', $branchId)->where('status', 'active'))],
            'properties.*.source_owner_agreement_id' => ['required', 'integer', Rule::exists('owner_agreements', 'id')->where(fn ($query) => $query->where('branch_id', $branchId))],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3'],
            'payment_count' => ['required', 'integer', 'min:1'],
            'payment_frequency' => ['nullable', 'string', 'max:30'],
            'payment_mode' => ['required', 'in:cash,cheque,bank_transfer'],
            'installments' => ['required', 'array'],
            'installments.*.installment_no' => ['required', 'integer', 'distinct', 'min:1'],
            'installments.*.due_date' => ['sometimes', 'date_format:Y-m-d'],
            'installments.*.amount' => ['sometimes', 'numeric', 'min:0.01', 'decimal:0,2'],
            'installments.*.category' => ['required', 'in:rent,security,commission'],
            'installments.*.particulars' => ['required', 'string', 'max:255'],
            'terms_text' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency_code' => $this->input('currency_code') ?: 'AED']);
    }

    protected function passedValidation(): void
    {
        $this->merge(['currency_code' => strtoupper($this->currency_code)]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (count($this->input('installments', [])) !== (int) $this->input('payment_count')) {
                $validator->errors()->add('installments', 'Installment schedule must match the number of installments.');
            }
            $this->validateInstallmentSchedule($validator, (int) $this->input('payment_count'), $this->input('total_amount'), $this->input('start_date'), $this->input('end_date'));
        });
    }
}
