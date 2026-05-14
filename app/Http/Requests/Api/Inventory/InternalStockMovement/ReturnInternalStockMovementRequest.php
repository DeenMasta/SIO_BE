<?php

namespace App\Http\Requests\Api\Inventory\InternalStockMovement;

use App\Http\Requests\Api\StrictFormRequest;

class ReturnInternalStockMovementRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'movement_number' => ['required', 'string', 'max:50', 'alpha_dash'],
            'movement_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.stock_item_ids' => ['nullable', 'array'],
            'lines.*.stock_item_ids.*' => ['integer', 'distinct', 'exists:stock_items,id'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'movement_number',
            'movement_date',
            'remarks',
            'lines',
        ];
    }
}
