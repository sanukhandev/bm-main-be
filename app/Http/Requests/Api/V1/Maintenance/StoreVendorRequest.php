<?php

namespace App\Http\Requests\Api\V1\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:180'], 'customer_type' => ['nullable', 'in:individual,organization'], 'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'status' => ['nullable', 'in:active,inactive']];
    }
}
