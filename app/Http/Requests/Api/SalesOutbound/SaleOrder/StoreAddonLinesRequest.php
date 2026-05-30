<?php

namespace App\Http\Requests\Api\SalesOutbound\SaleOrder;

use App\Http\Requests\Api\StrictFormRequest;

class StoreAddonLinesRequest extends StrictFormRequest
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.ordered_qty' => ['required', 'integer', 'min:1'],
            'lines.*.is_free' => ['nullable', 'boolean'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['lines'];
    }
}
