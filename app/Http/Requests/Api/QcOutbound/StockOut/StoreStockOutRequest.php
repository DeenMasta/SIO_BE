<?php

namespace App\Http\Requests\Api\QcOutbound\StockOut;

use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Validator;

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
            'customer_exchange_id' => ['nullable', 'integer', 'exists:customer_exchanges,id'],
            'stock_out_number' => ['required', 'string', 'max:50', 'alpha_dash'],
            'idempotency_key' => ['required', 'string', 'max:80'],
            'stock_out_date' => ['required', 'date'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'invoice_number' => ['nullable', 'string', 'max:50'],
            'packing_verified' => ['nullable', 'boolean'],
            'pick_list_reference' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'lines' => ['nullable', 'array'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.sale_order_line_id' => ['nullable', 'integer', 'exists:sale_order_lines,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.stock_item_ids' => ['nullable', 'array'],
            'lines.*.stock_item_ids.*' => ['integer', 'distinct', 'exists:stock_items,id'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
            'extra_lines' => ['nullable', 'array'],
            'extra_lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'extra_lines.*.qty' => ['required', 'integer', 'min:1'],
            'extra_lines.*.stock_item_ids' => ['nullable', 'array'],
            'extra_lines.*.stock_item_ids.*' => ['integer', 'distinct', 'exists:stock_items,id'],
            'extra_lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            $lines = array_values(array_filter((array) $this->input('lines', []), static fn (mixed $line): bool => is_array($line)));
            $extraLines = array_values(array_filter((array) $this->input('extra_lines', []), static fn (mixed $line): bool => is_array($line)));

            if ($lines === [] && $extraLines === []) {
                $validator->errors()->add('lines', 'At least one regular or extra stock out line is required.');
            }
        });
    }

    protected function allowedFields(): array
    {
        return [
            'sale_order_id',
            'customer_exchange_id',
            'stock_out_number',
            'idempotency_key',
            'stock_out_date',
            'customer_id',
            'invoice_number',
            'packing_verified',
            'pick_list_reference',
            'remarks',
            'lines',
            'extra_lines',
        ];
    }
}
