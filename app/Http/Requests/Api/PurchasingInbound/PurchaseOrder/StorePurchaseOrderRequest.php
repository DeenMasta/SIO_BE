<?php

namespace App\Http\Requests\Api\PurchasingInbound\PurchaseOrder;

use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends StrictFormRequest
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
            'po_number' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:purchase_orders,po_number'],
            'po_date' => ['required', 'date'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'expected_delivery_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::enum(PurchaseOrderStatus::class)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.ordered_qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'po_number',
            'po_date',
            'supplier_id',
            'expected_delivery_date',
            'status',
            'remarks',
            'lines',
        ];
    }
}
