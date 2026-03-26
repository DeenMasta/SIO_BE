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
            'delivery_order_number' => $this->delivery_order_number,
            'purchase_order_id' => $this->purchase_order_id,
            'supplier_id' => $this->supplier_id,
            'stock_in_pic_id' => $this->stock_in_pic_id,
            'qc_person_id' => $this->qc_person_id,
            'status' => $this->status?->value,
            'remarks' => $this->remarks,
            'lines' => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'received_qty' => $line->received_qty,
                'condition_at_receiving' => $line->condition_at_receiving,
                'remarks' => $line->remarks,
                'stock_items' => $line->stockItems->map(fn ($item): array => [
                    'id' => $item->id,
                    'serial_number' => $item->serial_number,
                    'serial_source' => $item->serial_source?->value,
                    'current_status' => $item->current_status?->value,
                ]),
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
