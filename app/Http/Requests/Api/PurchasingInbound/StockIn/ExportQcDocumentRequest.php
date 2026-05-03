<?php

namespace App\Http\Requests\Api\PurchasingInbound\StockIn;

use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class ExportQcDocumentRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'string', Rule::in(['POSTED'])],
            'modified' => ['nullable', 'string', Rule::in(['updated', 'original'])],
            'format' => ['nullable', 'string', Rule::in(['csv', 'xlsx', 'pdf'])],
        ];
    }

    protected function allowedFields(): array
    {
        return ['q', 'date_from', 'date_to', 'status', 'modified', 'format'];
    }
}
