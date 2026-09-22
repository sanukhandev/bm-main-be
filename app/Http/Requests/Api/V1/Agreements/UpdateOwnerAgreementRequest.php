<?php

namespace App\Http\Requests\Api\V1\Agreements;

use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOwnerAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = app(BranchContext::class)->id();
        $agreementId = $this->route('owner_agreement')?->getKey();

        return [
            'agreement_no' => ['sometimes', 'required', 'string', 'max:64', Rule::unique('owner_agreements', 'agreement_no')->where(fn ($query) => $query->where('branch_id', $branchId))->ignore($agreementId)],
            'owner_customer_id' => ['sometimes', 'required', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('branch_id', $branchId)->where('status', 'active'))],
            'property_ids' => ['sometimes', 'array', 'min:1'],
            'property_ids.*' => ['integer', 'distinct', Rule::exists('properties', 'id')->where(fn ($query) => $query->where('branch_id', $branchId)->where('status', 'active'))],
            'start_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'total_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'currency_code' => ['sometimes', 'required', 'string', 'size:3'],
            'payment_count' => ['sometimes', 'required', 'integer', 'min:1'],
            'payment_frequency' => ['sometimes', 'nullable', 'string', 'max:30'],
            'payment_mode' => ['sometimes', 'required', 'in:cash,cheque,bank_transfer'],
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
}
