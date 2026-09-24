<?php

namespace App\Http\Requests\Api\V1\Agreements;

use Illuminate\Foundation\Http\FormRequest;

class LifecycleActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $action = $this->route('action');

        return [
            'reason' => ['nullable', 'string', 'max:2000'],
            'new_end_date' => [$action === 'extend' ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'start_date' => [$action === 'renew' ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'end_date' => [$action === 'renew' ? 'required' : 'sometimes', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }
}
