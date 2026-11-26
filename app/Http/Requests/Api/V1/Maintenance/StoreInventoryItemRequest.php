<?php

namespace App\Http\Requests\Api\V1\Maintenance;

use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryItemRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('sku')) {
            $this->merge(['sku' => strtoupper(trim((string) $this->input('sku')))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $uniqueSku = Rule::unique('inventory_items', 'sku')->where('branch_id', app(BranchContext::class)->id());
        if ($item = $this->route('item')) {
            $uniqueSku->ignore($item);
        }

        return ['sku' => ['required', 'string', 'max:80', $uniqueSku], 'name' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string'], 'unit_of_measure' => ['required', 'string', 'max:30'], 'reorder_level' => ['nullable', 'numeric', 'min:0'], 'opening_quantity' => ['nullable', 'numeric', 'min:0'], 'status' => ['nullable', 'in:active,inactive']];
    }
}
