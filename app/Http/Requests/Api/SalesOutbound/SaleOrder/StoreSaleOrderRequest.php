<?php

namespace App\Http\Requests\Api\SalesOutbound\SaleOrder;

use App\Domain\SalesOutbound\Enums\SaleOrderStatus;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreSaleOrderRequest extends StrictFormRequest
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
            'so_number' => ['nullable', 'string', 'max:50', 'alpha_dash', 'unique:sale_orders,so_number'],
            'so_date' => ['required', 'date'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'expected_delivery_date' => ['nullable', 'date'],
            'invoice_number' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::enum(SaleOrderStatus::class)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.ordered_qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $soNumber = $this->has('so_number')
            ? trim((string) $this->input('so_number'))
            : null;

        $this->merge([
            'so_number' => $soNumber !== '' ? $soNumber : null,
        ]);
    }

    protected function allowedFields(): array
    {
        return [
            'so_number',
            'so_date',
            'customer_id',
            'expected_delivery_date',
            'invoice_number',
            'status',
            'remarks',
            'lines',
        ];
    }
}
