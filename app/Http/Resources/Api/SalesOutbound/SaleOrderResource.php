<?php

namespace App\Http\Resources\Api\SalesOutbound;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'so_number' => $this->so_number,
            'so_date' => $this->so_date,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer?->customer_name,
            'expected_delivery_date' => $this->expected_delivery_date,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status?->value,
            'created_by' => $this->created_by,
            'remarks' => $this->remarks,
            'lines' => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_code' => $line->product?->product_code,
                'product_name' => $line->product?->product_name,
                'product_type' => $line->product?->product_type?->value,
                'ordered_qty' => $line->ordered_qty,
                'fulfilled_qty' => $line->fulfilled_qty,
                'remaining_qty' => max((int) $line->ordered_qty - (int) $line->fulfilled_qty, 0),
                'is_free' => (bool) $line->is_free,
                'unit_price' => (string) $line->unit_price,
                'subtotal' => (string) $line->subtotal,
                'remarks' => $line->remarks,
                // Load dispatched serials if the relation was eager loaded or just query if not (keep simple)
                'dispatched_serials' => $line->relationLoaded('dispatchedItems') 
                    ? $line->dispatchedItems->map(fn($item) => $item->stockItem?->serial_number)->filter()->values()->all()
                    : [],
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
