<?php

namespace App\Http\Requests\Api\V1\Properties;

use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = app(BranchContext::class)->id();

        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,archived'],
            'property_type' => ['nullable', 'string', 'max:30'],
            'owner_customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('branch_id', $branchId))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:created_at,-created_at,name,-name,property_code,-property_code,unit_number,-unit_number'],
        ];
    }
}
