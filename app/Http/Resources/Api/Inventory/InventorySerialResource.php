<?php

namespace App\Http\Resources\Api\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InventorySerialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'product_id' => (int) $this->product_id,
            'stock_in_line_id' => (int) $this->stock_in_line_id,
            'serial_number' => $this->serial_number,
            'current_status' => $this->enumValue($this->current_status),
            'qc_status' => $this->enumValue($this->qc_status),
            'received_condition' => $this->received_condition,
            'last_movement_at' => $this->last_movement_at,
            'stock_in_number' => $this->stock_in_number,
            'stock_in_date' => $this->stock_in_date,
            'remarks' => $this->remarks,
        ];
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
