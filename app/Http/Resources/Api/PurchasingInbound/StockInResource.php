<?php

namespace App\Http\Resources\Api\PurchasingInbound;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockInResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stock_in_number' => $this->stock_in_number,
            'stock_in_date' => $this->stock_in_date,
            'purchase_order_id' => $this->purchase_order_id,
            'supplier_id' => $this->supplier_id,
            'stock_in_pic_id' => $this->stock_in_pic_id,
            'status' => $this->status?->value === 'POSTED'
                ? 'RECEIVED'
                : $this->status?->value,
            'remarks' => $this->remarks,
            'lines' => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'purchase_order_line_id' => $line->purchase_order_line_id,
                'product_id' => $line->product_id,
                'product_code' => $line->product?->product_code,
                'product_name' => $line->product?->product_name,
                'product_type' => $line->product?->product_type?->value,
                'received_qty' => $line->received_qty,
                'condition_at_receiving' => $line->condition_at_receiving,
                'remarks' => $line->remarks,
                'stock_items' => $line->stockItems->map(fn ($item): array => [
                    'id' => $item->id,
                    'serial_number' => $item->serial_number,
                    'serial_source' => $item->serial_source?->value,
                    'current_status' => $item->current_status?->value,
                    'received_condition' => $item->received_condition,
                ]),
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
