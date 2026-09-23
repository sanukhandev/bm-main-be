<?php

namespace App\Http\Requests\Api\V1\Properties;

use App\Enums\PropertyType;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = app(BranchContext::class)->id();

        return [
            'owner_customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('branch_id', $branchId)->where('status', 'active'))],
            'property_code' => ['required', 'string', 'max:64', Rule::unique('properties', 'property_code')->where(fn ($query) => $query->where('branch_id', $branchId))],
            'unit_number' => ['nullable', 'string', 'max:100'],
            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'name' => ['required', 'string', 'max:255'],
            'building_name' => ['nullable', 'string', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state_or_emirate' => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'metadata_json' => ['nullable', 'array'],
        ];
    }

    protected function passedValidation(): void
    {
        $this->merge(['country_code' => $this->country_code ? strtoupper($this->country_code) : null]);
    }
}
