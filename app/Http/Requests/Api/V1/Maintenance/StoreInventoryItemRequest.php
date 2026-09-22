<?php

namespace App\Http\Requests\Api\V1\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['sku' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string'], 'unit_of_measure' => ['required', 'string', 'max:30'], 'reorder_level' => ['nullable', 'numeric', 'min:0'], 'opening_quantity' => ['nullable', 'numeric', 'min:0'], 'status' => ['nullable', 'in:active,inactive']];
    }
}
