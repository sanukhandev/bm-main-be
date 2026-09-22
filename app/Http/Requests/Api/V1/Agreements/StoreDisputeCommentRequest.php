<?php

namespace App\Http\Requests\Api\V1\Agreements;

use Illuminate\Foundation\Http\FormRequest;

class StoreDisputeCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['comment' => ['required', 'string', 'max:10000']];
    }
}
