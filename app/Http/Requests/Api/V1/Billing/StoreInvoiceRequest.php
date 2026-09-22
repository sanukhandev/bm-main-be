<?php

namespace App\Http\Requests\Api\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['quotation_id' => ['nullable', 'integer'], 'work_order_id' => ['nullable', 'integer'], 'vendor_id' => ['nullable', 'integer'], 'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string'], 'invoice_date' => ['required', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d'], 'tax_amount' => ['nullable', 'numeric', 'min:0'], 'status' => ['nullable', 'in:draft,issued,paid,void'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.particulars' => ['required', 'string', 'max:255'], 'lines.*.quantity' => ['required', 'numeric', 'min:0.01'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0']];
    }
}
