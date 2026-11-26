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
            ])],
            'page' => ['required', 'string', 'max:255'],
            'entity_type' => ['nullable', 'string', 'max:80'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['sometimes', 'array', 'max:20'],
            'metadata.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $reserved = ['page', 'entity_type', 'entity_id', 'source'];
            foreach (array_keys((array) $this->input('metadata', [])) as $key) {
                if (! preg_match('/^[a-z0-9_]{1,40}$/', (string) $key) || in_array($key, $reserved, true)) {
                    $validator->errors()->add('metadata', 'Metadata keys must be valid and cannot replace server-owned fields.');

                    return;
                }
            }
        });
    }
}
