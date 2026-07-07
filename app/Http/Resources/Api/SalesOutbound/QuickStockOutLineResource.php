<?php

namespace App\Http\Resources\Api\SalesOutbound;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuickStockOutLineResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product'),
            'quantity' => $this->quantity,
            'remarks' => $this->remarks,
            'serials' => $this->whenLoaded('lineItems', fn (): array => $this->lineItems->map(fn ($item): array => [
                'id' => $item->id,
                'stock_item_id' => $item->stock_item_id,
                'serial_number' => $item->serial_number_snapshot,
                'stock_out_line_item_id' => $item->stock_out_line_item_id,
            ])->values()->all()),
        ];
    }
}
