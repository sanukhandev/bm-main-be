<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() === true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in([
                'opened', 'viewed', 'printed', 'downloaded',
                'updated', 'created', 'approved', 'commenced', 'drafted', 'deleted',
            ])],
            'page' => ['required', 'string', 'max:255'],
            'entity_type' => ['nullable', 'string', 'max:80'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['sometimes', 'array', 'max:20'],
            'metadata.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
