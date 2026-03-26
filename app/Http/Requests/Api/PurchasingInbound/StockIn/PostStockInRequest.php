<?php

namespace App\Http\Requests\Api\PurchasingInbound\StockIn;

use App\Http\Requests\Api\StrictFormRequest;

class PostStockInRequest extends StrictFormRequest
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
            'stock_in_number' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:stock_in,stock_in_number'],
            'stock_in_date' => ['required', 'date'],
            'delivery_order_number' => ['nullable', 'string', 'max:50'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'qc_person_id' => ['nullable', 'integer', 'exists:users,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.received_qty' => ['required', 'integer', 'min:1'],
            'lines.*.condition_at_receiving' => ['nullable', 'string', 'max:50'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
            'lines.*.serial_numbers' => ['nullable', 'array'],
            'lines.*.serial_numbers.*' => ['string', 'max:80', 'distinct', 'unique:stock_items,serial_number'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'stock_in_number',
            'stock_in_date',
            'delivery_order_number',
            'purchase_order_id',
            'supplier_id',
            'qc_person_id',
            'remarks',
            'lines',
        ];
    }
}
