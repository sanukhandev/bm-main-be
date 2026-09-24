<?php

namespace App\Http\Requests\Api\V1\Properties;

use App\Enums\PropertyType;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = app(BranchContext::class)->id();
        $propertyId = $this->route('property')?->getKey();

        return [
            'property_code' => ['sometimes', 'required', 'string', 'max:64', Rule::unique('properties', 'property_code')->where(fn ($query) => $query->where('branch_id', $branchId))->ignore($propertyId)],
            'unit_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'property_type' => ['sometimes', 'required', Rule::enum(PropertyType::class)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'building_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'state_or_emirate' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'area' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'metadata_json' => ['sometimes', 'nullable', 'array'],
        ];
    }

    protected function passedValidation(): void
    {
        if ($this->has('country_code')) {
            $this->merge(['country_code' => $this->country_code ? strtoupper($this->country_code) : null]);
        }
    }
}
