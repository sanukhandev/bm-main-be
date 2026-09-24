<?php

namespace App\Http\Requests;

use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntelligentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounts.view', app(BranchContext::class)->id()) ?? false;
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(['this_month', 'last_month', 'last_3_months', 'last_6_months', 'last_12_months', 'this_year', 'custom'])],
            'date_from' => ['nullable', 'date', 'required_if:period,custom'],
            'date_to' => ['nullable', 'date', 'required_if:period,custom', 'after_or_equal:date_from'],
            'scope' => ['nullable', Rule::in(['branch', 'overall'])],
        ];
    }
}
