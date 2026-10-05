<?php

namespace App\Http\Requests\Api\V1\Agreements;

use App\Http\Requests\Api\V1\Agreements\Concerns\ValidatesAgreementInstallments;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantAgreementRequest extends FormRequest
{
    use ValidatesAgreementInstallments;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = app(BranchContext::class)->id();
        $agreementId = $this->route('tenant_agreement')?->getKey();

        return [
            'agreement_no' => ['sometimes', 'required', 'string', 'max:64', Rule::unique('tenant_agreements', 'agreement_no')->where(fn ($query) => $query->where('branch_id', $branchId))->ignore($agreementId)],
            'file_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tenant_customer_id' => ['sometimes', 'required', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('branch_id', $branchId)->where('status', 'active'))],
            'properties' => ['sometimes', 'array', 'min:1'],
            'properties.*.property_id' => ['required', 'integer', 'distinct', Rule::exists('properties', 'id')->where(fn ($query) => $query->where('branch_id', $branchId)->where('status', 'active'))],
            'properties.*.source_owner_agreement_id' => ['required', 'integer', Rule::exists('owner_agreements', 'id')->where(fn ($query) => $query->where('branch_id', $branchId))],
            'start_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'total_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'currency_code' => ['sometimes', 'required', 'string', 'size:3'],
            'payment_count' => ['sometimes', 'required', 'integer', 'min:1'],
            'payment_frequency' => ['sometimes', 'nullable', 'string', 'max:30'],
            'payment_mode' => ['sometimes', 'required', 'in:cash,cheque,bank_transfer'],
            'installments' => ['sometimes', 'array'],
            'installments.*.installment_no' => ['required', 'integer', 'distinct', 'min:1'],
            'installments.*.due_date' => ['sometimes', 'date_format:Y-m-d'],
            'installments.*.amount' => ['sometimes', 'numeric', 'min:0.01', 'decimal:0,2'],
            'installments.*.category' => ['required', 'in:rent,security,commission'],
            'installments.*.particulars' => ['required', 'string', 'max:255'],
            'terms_text' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function passedValidation(): void
    {
        if ($this->has('currency_code')) {
            $this->merge(['currency_code' => strtoupper($this->currency_code)]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->has('installments')) {
                return;
            }
            $agreement = $this->route('tenant_agreement');
            $count = (int) $this->input('payment_count', $agreement?->payment_count);
            $agreement = $this->route('tenant_agreement');
            $this->validateInstallmentSchedule(
                $validator,
                $count,
                $this->input('total_amount', $agreement?->total_amount),
                $this->input('start_date', $agreement?->start_date?->format('Y-m-d')),
                $this->input('end_date', $agreement?->end_date?->format('Y-m-d')),
            );
        });
    }
}
