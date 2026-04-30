<?php

namespace App\Http\Requests\Api\ExceptionsReturns\ReturnToSupplier;

use App\Domain\ExceptionsReturns\Enums\ExceptionReason;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreReturnToSupplierRequest extends StrictFormRequest
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
            'rts_transaction_number' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:return_to_supplier,rts_transaction_number'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'stock_in_id' => ['required', 'integer', 'exists:stock_in,id'],
            'return_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.stock_in_line_id' => ['required', 'integer', 'exists:stock_in_lines,id'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.stock_item_id' => ['nullable', 'integer', 'exists:stock_items,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.reason_for_return' => ['required', 'string', Rule::in(ExceptionReason::values())],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'rts_transaction_number',
            'supplier_id',
            'stock_in_id',
            'return_date',
            'remarks',
            'lines',
        ];
    }
}
