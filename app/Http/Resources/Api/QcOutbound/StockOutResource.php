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
            'stock_out_number' => $this->stock_out_number,
            'idempotency_key' => $this->idempotency_key,
            'stock_out_date' => $this->stock_out_date,
            'customer_id' => $this->customer_id,
            'invoice_number' => $this->invoice_number,
            'pic_id' => $this->pic_id,
            'pick_list_reference' => $this->pick_list_reference,
            'packing_verified' => $this->packing_verified,
            'status' => $this->status?->value,
            'remarks' => $this->remarks,
            'lines' => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'qty' => $line->qty,
                'remarks' => $line->remarks,
                'stock_item_ids' => $line->lineItems->pluck('stock_item_id')->values(),
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
