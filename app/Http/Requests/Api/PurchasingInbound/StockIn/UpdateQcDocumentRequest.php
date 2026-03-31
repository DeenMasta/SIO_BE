<?php

namespace App\Http\Requests\Api\PurchasingInbound\StockIn;

use App\Http\Requests\Api\StrictFormRequest;

class UpdateQcDocumentRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer', 'exists:qc_items,id'],
            'lines.*.checked_conditions' => ['nullable', 'array'],
            'lines.*.checked_conditions.*' => ['string', 'max:100'],
            'lines.*.checked_accessories' => ['nullable', 'array'],
            'lines.*.checked_accessories.*' => ['string', 'max:100'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'date',
            'remarks',
            'lines',
        ];
    }
}
