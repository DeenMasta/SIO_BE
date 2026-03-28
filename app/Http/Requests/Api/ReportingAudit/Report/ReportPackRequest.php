<?php

namespace App\Http\Requests\Api\ReportingAudit\Report;

use App\Http\Requests\Api\StrictFormRequest;

class ReportPackRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'po_number' => ['nullable', 'string', 'max:50'],
            'invoice_number' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:30'],
            'age_bucket' => ['nullable', 'string', 'in:0_7,8_30,31_plus'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'date_from',
            'date_to',
            'per_page',
            'supplier_id',
            'customer_id',
            'product_id',
            'po_number',
            'invoice_number',
            'status',
            'age_bucket',
        ];
    }
}
