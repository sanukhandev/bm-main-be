<?php

namespace App\Http\Requests\Api\V1\Administration;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permission_keys' => ['sometimes', 'array'],
            'permission_keys.*' => ['string', 'exists:permissions,key'],
        ];
    }
}
