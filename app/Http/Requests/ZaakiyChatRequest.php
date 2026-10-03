<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ZaakiyChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() === true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'history' => ['sometimes', 'array', 'max:20'],
            'history.*.role' => ['required', 'in:user,model'],
            'history.*.text' => ['required', 'string', 'max:4000'],
            'conversation_context' => ['sometimes', 'array'],
            'conversation_context.intent' => ['nullable', 'string', 'max:100'],
            'conversation_context.domain' => ['nullable', 'string', 'max:50'],
            'conversation_context.subject' => ['nullable', 'string', 'max:255'],
            'conversation_context.metric' => ['nullable', 'string', 'max:100'],
            'conversation_context.entities' => ['sometimes', 'array', 'max:10'],
            'conversation_context.entities.*' => ['array'],
            'conversation_context.entities.*.type' => ['required_with:conversation_context.entities.*.id', 'string', 'in:customer,owner,tenant,property,owner_agreement,tenant_agreement,payment,work_order,invoice,quotation'],
            'conversation_context.entities.*.id' => ['required_with:conversation_context.entities.*.type', 'integer', 'min:1'],
            'conversation_context.entities.*.label' => ['nullable', 'string', 'max:255'],
            'conversation_context.time_range' => ['nullable', 'array'],
            'conversation_context.time_range.from' => ['nullable', 'date'],
            'conversation_context.time_range.to' => ['nullable', 'date', 'after_or_equal:conversation_context.time_range.from'],
            'conversation_context.time_range.label' => ['nullable', 'string', 'max:100'],
            'conversation_context.comparison_range' => ['nullable', 'array'],
            'conversation_context.comparison_range.from' => ['nullable', 'date'],
            'conversation_context.comparison_range.to' => ['nullable', 'date', 'after_or_equal:conversation_context.comparison_range.from'],
            'conversation_context.comparison_range.label' => ['nullable', 'string', 'max:100'],
            'conversation_context.filters' => ['sometimes', 'array', 'max:10'],
            'conversation_context.sort' => ['sometimes', 'array', 'max:3'],
            'conversation_context.sort.field' => ['nullable', 'string', 'max:64'],
            'conversation_context.sort.direction' => ['nullable', 'in:asc,desc'],
            'conversation_context.result_references' => ['sometimes', 'array', 'max:25'],
            'conversation_context.result_references.*' => ['array'],
            'conversation_context.result_references.*.type' => ['required_with:conversation_context.result_references.*.id', 'string', 'in:customer,owner,tenant,property,owner_agreement,tenant_agreement,payment,work_order,invoice,quotation'],
            'conversation_context.result_references.*.id' => ['required_with:conversation_context.result_references.*.type', 'integer', 'min:1'],
            'conversation_context.result_references.*.label' => ['nullable', 'string', 'max:255'],
            'conversation_context.branch_context' => ['sometimes', 'array', 'max:3'],
            'conversation_context.branch_context.branch_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
