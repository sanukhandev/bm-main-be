<?php

namespace App\Http\Requests\Api\V1\Properties;

use App\Enums\PropertyType;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AvailablePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = app(BranchContext::class)->id();

        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'source_owner_agreement_id' => ['nullable', 'integer', Rule::exists('owner_agreements', 'id')->where(fn ($query) => $query->where('branch_id', $branchId))],
            'exclude_tenant_agreement_id' => ['nullable', 'integer', Rule::exists('tenant_agreements', 'id')->where(fn ($query) => $query->where('branch_id', $branchId))],
            'property_type' => ['nullable', Rule::enum(PropertyType::class)],
            'owner_customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('branch_id', $branchId))],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
