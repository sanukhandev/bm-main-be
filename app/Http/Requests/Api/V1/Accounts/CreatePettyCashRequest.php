<?php

namespace App\Http\Requests\Api\V1\Accounts;

use Illuminate\Foundation\Http\FormRequest;

class CreatePettyCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'direction' => ['required', 'in:inward,outward'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'particulars' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
