<?php

namespace App\Http\Requests\Api\ReportingAudit\Search;

use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class SearchCatalogRequest extends StrictFormRequest
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
            'page' => ['nullable', 'integer', 'min:1'],
            'query' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'current_status' => [
                'nullable',
                'string',
                Rule::in([
                    'RECEIVED',
                    'IN_STOCK',
                    'DELIVERED',
                    'UNDER_REPAIR',
                    'RETURNED_TO_SUPPLIER',
                    'RETURNED',
                ]),
            ],
            'qc_status' => [
                'nullable',
                'string',
                Rule::in(['PENDING', 'PARTIAL', 'PASSED', 'FAILED']),
            ],
            'is_available' => ['nullable', 'boolean'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'page',
            'query',
            'per_page',
            'product_id',
            'current_status',
            'qc_status',
            'is_available',
        ];
    }
}
