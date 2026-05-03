<?php

namespace App\Http\Resources\Api\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InventoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_id' => (int) $this->product_id,
            'product_code' => $this->product_code,
            'product_name' => $this->product_name,
            'product_model' => $this->product_model,
            'product_type' => $this->enumValue($this->product_type),
            'requires_serial_number' => (bool) $this->requires_serial_number,
            'supplier_id' => $this->supplier_id !== null ? (int) $this->supplier_id : null,
            'supplier' => $this->supplier_id !== null ? [
                'id' => (int) $this->supplier_id,
                'supplier_code' => $this->supplier_code,
                'supplier_name' => $this->supplier_name,
            ] : null,
            'uom' => $this->uom,
            'reorder_level' => (int) $this->reorder_level,
            'status' => $this->enumValue($this->status),
            'stock_status' => $this->stock_status,
            'qty_available' => (int) $this->qty_available,
            'qty_in_stock' => (int) $this->qty_in_stock,
            'qty_available_serialized' => (int) $this->qty_available_serialized,
            'qty_delivered' => (int) $this->qty_delivered,
            'qty_under_repair' => (int) $this->qty_under_repair,
            'qty_returned' => (int) $this->qty_returned,
            'qty_returned_to_supplier' => (int) $this->qty_returned_to_supplier,
            'last_computed_at' => $this->last_computed_at,
        ];
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
