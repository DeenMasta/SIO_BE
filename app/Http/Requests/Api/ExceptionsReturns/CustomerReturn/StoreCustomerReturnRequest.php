<?php

namespace App\Http\Requests\Api\ExceptionsReturns\CustomerReturn;

use App\Domain\ExceptionsReturns\Enums\CustomerReturnNextAction;
use App\Domain\ExceptionsReturns\Enums\ExceptionReason;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerReturnRequest extends StrictFormRequest
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
            'return_transaction_number' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:customer_returns,return_transaction_number'],
            'return_date' => ['required', 'date'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'original_invoice_number' => ['required', 'string', 'max:50'],
            'original_stock_out_id' => ['required', 'integer', 'exists:stock_out,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.original_stock_out_line_id' => ['nullable', 'integer', 'exists:stock_out_lines,id'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.stock_item_id' => ['nullable', 'integer', 'exists:stock_items,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.reason_for_return' => ['required', 'string', Rule::in(ExceptionReason::values())],
            'lines.*.condition_on_return' => ['nullable', 'string', 'max:255'],
            'lines.*.next_action' => ['required', 'string', Rule::in(CustomerReturnNextAction::values())],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'return_transaction_number',
            'return_date',
            'customer_id',
            'original_invoice_number',
            'original_stock_out_id',
            'remarks',
            'lines',
        ];
    }
}
