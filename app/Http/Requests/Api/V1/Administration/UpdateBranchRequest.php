<?php

namespace App\Http\Requests\Api\V1\Administration;

class UpdateBranchRequest extends StoreBranchRequest
{
    public function rules(): array
    {
        return collect(parent::rules())->map(fn (array $rules) => array_merge(array_values(array_diff($rules, ['required'])), ['sometimes']))->all();
    }
}
