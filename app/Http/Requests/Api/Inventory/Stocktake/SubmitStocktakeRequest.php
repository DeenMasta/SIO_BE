<?php

namespace App\Http\Requests\Api\Inventory\Stocktake;

use App\Http\Requests\Api\StrictFormRequest;

class SubmitStocktakeRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['lines' => ['required', 'array', 'min:1'], 'lines.*.line_id' => ['required', 'integer', 'distinct', 'exists:stocktake_lines,id'], 'lines.*.counted_qty' => ['required', 'integer', 'min:0'], 'lines.*.counted_stock_item_ids' => ['nullable', 'array'], 'lines.*.counted_stock_item_ids.*' => ['integer', 'distinct', 'exists:stock_items,id'], 'lines.*.remarks' => ['nullable', 'string', 'max:2000']];
    }

    protected function allowedFields(): array
    {
        return ['lines'];
    }
}
