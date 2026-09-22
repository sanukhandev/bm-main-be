<?php

namespace App\Http\Requests\Api\V1\Agreements;

use Illuminate\Foundation\Http\FormRequest;

class StoreAgreementDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['subject' => ['required', 'string', 'max:180'], 'description' => ['required', 'string', 'max:10000']];
    }
}
