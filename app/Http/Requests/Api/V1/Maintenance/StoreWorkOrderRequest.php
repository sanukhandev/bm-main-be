<?php

namespace App\Http\Requests\Api\V1\Maintenance;

use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = app(BranchContext::class)->id();

        return ['property_id' => ['required', 'integer', Rule::exists('properties', 'id')->where(fn ($q) => $q->where('branch_id', $branchId))], 'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where(fn ($q) => $q->where('branch_id', $branchId))], 'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string'], 'priority' => ['required', 'in:low,normal,high,urgent'], 'service_charge' => ['nullable', 'numeric', 'min:0'], 'lines' => ['nullable', 'array'], 'lines.*.line_type' => ['required', 'in:service,inventory'], 'lines.*.inventory_item_id' => ['nullable', 'integer', Rule::exists('inventory_items', 'id')->where(fn ($q) => $q->where('branch_id', $branchId))], 'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_cost' => ['required', 'numeric', 'min:0']];
    }
}
