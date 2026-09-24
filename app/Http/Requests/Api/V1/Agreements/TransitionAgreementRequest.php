<?php

namespace App\Http\Requests\Api\V1\Agreements;

use App\Enums\AgreementStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(AgreementStatus::values())], 'reason' => ['nullable', 'string', 'max:2000']];
    }
}
