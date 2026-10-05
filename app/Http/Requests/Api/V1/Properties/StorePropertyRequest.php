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
            'property_code' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('properties', 'property_code')->where(fn ($query) => $query->where('branch_id', $branchId))],
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
            'electricity_provider' => ['nullable', 'string', Rule::in(['dewa', 'addc', 'aadc', 'sewa', 'etihadwe', 'other'])],
            'electricity_account_number' => ['nullable', 'string', 'max:100'],
            'cooling_provider' => ['nullable', 'string', Rule::in(['empower', 'emicool', 'tabreed', 'nakheel', 'other'])],
            'cooling_account_number' => ['nullable', 'string', 'max:100'],
            'gas_provider' => ['nullable', 'string', Rule::in(['emirates_gas', 'enoc', 'adnoc', 'lootah_gas', 'dubai_gas', 'other'])],
            'gas_connection_type' => ['nullable', 'string', Rule::in(['piped_gas', 'lpg_cylinder', 'bulk_lpg', 'other'])],
            'gas_connection_number' => ['nullable', 'string', 'max:100'],
            'utility_details' => ['sometimes', 'nullable', 'array'],
            'utility_details.*.type' => ['required', 'string', Rule::in(['electricity', 'cooling', 'gas', 'furniture']), 'distinct'],
            'utility_details.*.provider' => ['nullable', 'string', 'max:40'],
            'utility_details.*.account_number' => ['nullable', 'string', 'max:100'],
            'utility_details.*.connection_type' => ['nullable', 'string', Rule::in(['piped_gas', 'lpg_cylinder', 'bulk_lpg', 'other'])],
            'utility_details.*.connection_number' => ['nullable', 'string', 'max:100'],
            'utility_details.*.details' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string'],
            'metadata_json' => ['nullable', 'array'],
        ];
    }

    protected function passedValidation(): void
    {
        $this->merge(['country_code' => $this->country_code ? strtoupper($this->country_code) : null]);
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
