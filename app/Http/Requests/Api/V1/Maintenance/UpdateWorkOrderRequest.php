<?php

namespace App\Http\Requests\Api\V1\Maintenance;

use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = app(BranchContext::class)->id();

        return [
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where(fn ($q) => $q->where('branch_id', $branchId))],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'priority' => ['required', 'in:low,normal,high,urgent'],
            'service_charge' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'in:open,assigned,in_progress,completed,cancelled'],
        ];
    }
}
