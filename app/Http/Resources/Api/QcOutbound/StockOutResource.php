<?php

namespace App\Http\Resources\Api\QcOutbound;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockOutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_order_id' => $this->sale_order_id,
            'sale_order_number' => $this->saleOrder?->so_number,
            'stock_out_number' => $this->stock_out_number,
            'idempotency_key' => $this->idempotency_key,
            'stock_out_date' => $this->stock_out_date,
            'customer_id' => $this->customer_id,
            'pick_list_reference' => $this->pick_list_reference,
            'pic_id' => $this->pic_id,
            'status' => $this->status?->value,
            'remarks' => $this->remarks,
            'lines' => $this->lines->map(fn ($line): array => [
                'id'             => $line->id,
                'product_id'     => $line->product_id,
                'qty'            => $line->qty,
                'remarks'        => $line->remarks,
                'stock_item_ids' => $line->lineItems->pluck('stock_item_id')->values(),
                'dispatched_items' => $line->lineItems->map(fn ($item): array => [
                    'stock_item_id'         => $item->stock_item_id,
                    'serial_number'         => $item->stockItem?->serial_number,
                ])->values(),
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
