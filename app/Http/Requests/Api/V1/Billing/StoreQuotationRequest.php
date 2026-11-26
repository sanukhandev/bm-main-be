<?php

namespace App\Http\Requests\Api\V1\Billing;

use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = app(BranchContext::class)->id();

        return ['work_order_id' => ['nullable', 'integer', Rule::exists('work_orders', 'id')->where('branch_id', $branchId)], 'vendor_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where(fn ($q) => $q->where('branch_id', $branchId)->where('status', 'active')->whereNull('deleted_at')->whereExists(fn ($role) => $role->selectRaw('1')->from('customer_role_assignments')->whereColumn('customer_role_assignments.customer_id', 'customers.id')->where('customer_role_assignments.branch_id', $branchId)->where('customer_role_assignments.role', 'vendor')))], 'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string'], 'quotation_date' => ['required', 'date_format:Y-m-d'], 'valid_until' => ['nullable', 'date_format:Y-m-d'], 'tax_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'], 'status' => ['prohibited'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.particulars' => ['required', 'string', 'max:255'], 'lines.*.quantity' => ['required', 'numeric', 'min:0.01'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2']];
    }
}
