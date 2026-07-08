<?php

namespace App\Http\Requests\Api\QcOutbound\StockOut;

use App\Http\Requests\Api\StrictFormRequest;

class ReverseStockOutExtrasRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.stock_out_line_id' => ['required', 'integer', 'exists:stock_out_lines,id'],
            'lines.*.reverse_qty' => ['nullable', 'integer', 'min:1'],
            'lines.*.stock_item_ids' => ['nullable', 'array', 'min:1'],
            'lines.*.stock_item_ids.*' => ['integer', 'distinct', 'exists:stock_items,id'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['lines'];
    }
}
