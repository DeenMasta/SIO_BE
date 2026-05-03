<?php

namespace App\Http\Requests\Api\Inventory;

use App\Http\Requests\Api\StrictFormRequest;

final class InventoryQueryRequest extends StrictFormRequest
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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'q' => ['nullable', 'string', 'max:100'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'stock_status' => ['nullable', 'string', 'in:in_stock,low_stock,out_of_stock'],
            'serial_page' => ['nullable', 'integer', 'min:1'],
            'serial_per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'serial_q' => ['nullable', 'string', 'max:100'],
            'movement_page' => ['nullable', 'integer', 'min:1'],
            'movement_per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function allowedFields(): array
    {
        return [
            'page',
            'per_page',
            'q',
            'product_id',
            'supplier_id',
            'stock_status',
            'serial_page',
            'serial_per_page',
            'serial_q',
            'movement_page',
            'movement_per_page',
        ];
    }
}
