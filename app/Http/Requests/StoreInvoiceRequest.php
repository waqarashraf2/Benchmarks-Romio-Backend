<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return in_array(auth()->user()->role, ['ceo', 'director', 'operations_manager','manger']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'invoice_number' => 'required|string|unique:invoices,invoice_number',
            'project_id' => 'required|exists:projects,id',
            'month' => 'required|string',
            'year' => 'required|string',
            'service_counts' => 'required|array',
            'total_amount' => 'nullable|numeric|min:0',
            'status' => 'sometimes|in:draft,pending_approval,approved,sent',
        ];
    }
}
