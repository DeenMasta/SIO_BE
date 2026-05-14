<?php

namespace App\Http\Resources\Api\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InternalStockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'movement_number' => $this->movement_number,
            'movement_date' => $this->movement_date,
            'movement_direction' => $this->movement_direction?->value,
            'purpose' => $this->purpose?->value,
            'original_movement_id' => $this->original_movement_id,
            'status' => $this->status,
            'remarks' => $this->remarks,
            'created_by' => $this->created_by,
            'lines' => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'stock_item_id' => $line->stock_item_id,
                'qty' => $line->qty,
                'remarks' => $line->remarks,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
