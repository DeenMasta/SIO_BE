<?php

namespace App\Http\Requests\Api\SalesOutbound\SaleOrder;

use App\Domain\SalesOutbound\Enums\SaleOrderStatus;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class UpdateSaleOrderRequest extends StrictFormRequest
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
            'so_number' => ['nullable', 'string', 'max:50', 'alpha_dash', Rule::unique('sale_orders', 'so_number')->ignore($this->route('saleOrder'))],
            'so_date' => ['required', 'date'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'expected_delivery_date' => ['nullable', 'date'],
            'invoice_number' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'lines' => ['nullable', 'array', 'min:1'],
            'lines.*.product_id' => ['required_with:lines', 'integer', 'exists:products,id'],
            'lines.*.ordered_qty' => ['required_with:lines', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('so_number')) {
            $soNumber = trim((string) $this->input('so_number'));
            $this->merge([
                'so_number' => $soNumber !== '' ? $soNumber : null,
            ]);
        }
    }

    protected function allowedFields(): array
    {
        return [
            'so_number',
            'so_date',
            'customer_id',
            'expected_delivery_date',
            'invoice_number',
            'remarks',
            'lines',
        ];
    }
}
