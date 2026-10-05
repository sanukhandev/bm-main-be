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
            'electricity_provider' => ['sometimes', 'nullable', 'string', Rule::in(['dewa', 'addc', 'aadc', 'sewa', 'etihadwe', 'other'])],
            'electricity_account_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cooling_provider' => ['sometimes', 'nullable', 'string', Rule::in(['empower', 'emicool', 'tabreed', 'nakheel', 'other'])],
            'cooling_account_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gas_provider' => ['sometimes', 'nullable', 'string', Rule::in(['emirates_gas', 'enoc', 'adnoc', 'lootah_gas', 'dubai_gas', 'other'])],
            'gas_connection_type' => ['sometimes', 'nullable', 'string', Rule::in(['piped_gas', 'lpg_cylinder', 'bulk_lpg', 'other'])],
            'gas_connection_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'utility_details' => ['sometimes', 'nullable', 'array'],
            'utility_details.*.type' => ['required', 'string', Rule::in(['electricity', 'cooling', 'gas', 'furniture']), 'distinct'],
            'utility_details.*.provider' => ['nullable', 'string', 'max:40'],
            'utility_details.*.account_number' => ['nullable', 'string', 'max:100'],
            'utility_details.*.connection_type' => ['nullable', 'string', Rule::in(['piped_gas', 'lpg_cylinder', 'bulk_lpg', 'other'])],
            'utility_details.*.connection_number' => ['nullable', 'string', 'max:100'],
            'utility_details.*.details' => ['nullable', 'string', 'max:2000'],
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

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $providers = [
                'electricity' => ['dewa', 'addc', 'aadc', 'sewa', 'etihadwe', 'other'],
                'cooling' => ['empower', 'emicool', 'tabreed', 'nakheel', 'other'],
                'gas' => ['emirates_gas', 'enoc', 'adnoc', 'lootah_gas', 'dubai_gas', 'other'],
            ];
            $utilities = $this->input('utility_details', []);
            if (! is_array($utilities)) {
                return;
            }

            foreach ($utilities as $index => $utility) {
                if (! is_array($utility)) {
                    continue;
                }
                $type = $utility['type'] ?? null;
                $provider = $utility['provider'] ?? null;
                if ($provider !== null && $provider !== '' && isset($providers[$type]) && ! in_array($provider, $providers[$type], true)) {
                    $validator->errors()->add("utility_details.{$index}.provider", 'The selected provider is not valid for this utility type.');
                }
                if ($type === 'furniture' && trim((string) ($utility['details'] ?? '')) === '') {
                    $validator->errors()->add("utility_details.{$index}.details", 'Please describe the included furniture.');
                }
            }
        });
    }
}
