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
            'original_movement_number' => $this->whenLoaded('originalMovement', fn (): ?string => $this->originalMovement?->movement_number),
            'status' => $this->status,
            'remarks' => $this->remarks,
            'created_by' => $this->created_by,
            'created_by_user' => $this->whenLoaded('createdByUser', function (): ?array {
                if (! $this->createdByUser) {
                    return null;
                }

                return [
                    'id' => $this->createdByUser->id,
                    'name' => $this->createdByUser->name,
                    'email' => $this->createdByUser->email,
                    'role' => $this->createdByUser->role?->value,
                    'status' => $this->createdByUser->status?->value,
                ];
            }),
            'pic' => $this->whenLoaded('createdByUser', fn (): ?array => $this->createdByUser ? [
                'id' => $this->createdByUser->id,
                'name' => $this->createdByUser->name,
                'email' => $this->createdByUser->email,
                'role' => $this->createdByUser->role?->value,
                'status' => $this->createdByUser->status?->value,
            ] : null),
            'lines' => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'stock_item_id' => $line->stock_item_id,
                'serial_number' => $line->relationLoaded('stockItem') ? $line->stockItem?->serial_number : null,
                'qty' => $line->qty,
                'remarks' => $line->remarks,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
