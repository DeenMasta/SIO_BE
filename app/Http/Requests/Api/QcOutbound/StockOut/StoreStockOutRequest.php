<?php

namespace App\Http\Requests\Api\QcOutbound\StockOut;

use App\Http\Requests\Api\StrictFormRequest;

class StoreStockOutRequest extends StrictFormRequest
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
            'sale_order_id' => ['nullable', 'integer', 'exists:sale_orders,id'],
            'stock_out_number' => ['required', 'string', 'max:50', 'alpha_dash'],
            'idempotency_key' => ['required', 'string', 'max:80'],
            'stock_out_date' => ['required', 'date'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'invoice_number' => ['required', 'string', 'max:50'],
            'pick_list_reference' => ['nullable', 'string', 'max:50'],
            'packing_verified' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.sale_order_line_id' => ['nullable', 'integer', 'exists:sale_order_lines,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.stock_item_ids' => ['nullable', 'array'],
            'lines.*.stock_item_ids.*' => ['integer', 'distinct', 'exists:stock_items,id'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'sale_order_id',
            'stock_out_number',
            'idempotency_key',
            'stock_out_date',
            'customer_id',
            'invoice_number',
            'pick_list_reference',
            'packing_verified',
            'remarks',
            'lines',
        ];
    }
}
