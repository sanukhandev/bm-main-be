<?php

namespace App\Http\Requests\Api\V1\Zaakiy;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmZaakiyActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() === true;
    }

    public function rules(): array
    {
        return [
            'overrides' => ['sometimes', 'array', 'max:20'],
        ];
    }
}
