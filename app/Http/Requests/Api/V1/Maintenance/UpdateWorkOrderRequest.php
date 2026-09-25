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
            'vendor_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where(fn ($q) => $q->where('branch_id', $branchId)->whereExists(fn ($role) => $role->selectRaw('1')->from('customer_role_assignments')->whereColumn('customer_role_assignments.customer_id', 'customers.id')->where('customer_role_assignments.branch_id', $branchId)->where('customer_role_assignments.role', 'vendor')))],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'priority' => ['required', 'in:low,normal,high,urgent'],
            'service_charge' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'in:open,assigned,in_progress,completed,cancelled'],
        ];
    }
}
