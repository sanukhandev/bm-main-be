<?php

namespace App\Http\Requests\Api\V1\Zaakiy;

use Illuminate\Foundation\Http\FormRequest;

class ZaakiyFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() === true;
    }

    public function rules(): array
    {
        return [
            'feedback' => ['required', 'in:helpful,unhelpful'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
