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
            'invoice_number' => $this->invoice_number,
            'pick_list_reference' => $this->pick_list_reference,
            'pic_id' => $this->pic_id,
            'pic' => $this->whenLoaded('pic', fn (): ?array => $this->pic ? [
                'id' => $this->pic->id,
                'name' => $this->pic->name,
                'email' => $this->pic->email,
                'role' => $this->pic->role?->value,
                'status' => $this->pic->status?->value,
            ] : null),
            'status' => $this->status?->value,
            'remarks' => $this->remarks,
            'lines' => $this->lines->map(fn ($line): array => [
                'id'             => $line->id,
                'sale_order_line_id' => $line->sale_order_line_id,
                'is_extra' => (bool) $line->is_extra,
                'product_id'     => $line->product_id,
                'product_code' => $line->product?->product_code,
                'product_name' => $line->product?->product_name,
                'product_type' => $line->product?->product_type?->value,
                'qty'            => $line->qty,
                'settled_qty' => (int) $line->settled_qty,
                'reversed_qty' => (int) $line->reversed_qty,
                'pending_qty' => max((int) $line->qty - (int) $line->settled_qty - (int) $line->reversed_qty, 0),
                'ordered_qty' => $line->saleOrderLine?->ordered_qty,
                'fulfilled_qty' => $line->saleOrderLine?->fulfilled_qty,
                'remaining_qty' => $line->saleOrderLine
                    ? max((int) $line->saleOrderLine->ordered_qty - (int) $line->saleOrderLine->fulfilled_qty, 0)
                    : null,
                'remarks'        => $line->remarks,
                'settled_sale_order_line_ids' => $line->settledSaleOrderLines->pluck('id')->values(),
                'stock_item_ids' => $line->lineItems->pluck('stock_item_id')->values(),
                'dispatched_items' => $line->lineItems->map(fn ($item): array => [
                    'stock_item_id'         => $item->stock_item_id,
                    'serial_number'         => $item->stockItem?->serial_number,
                    'settled_sale_order_line_id' => $item->settled_sale_order_line_id,
                    'reversed_at' => $item->reversed_at,
                ])->values(),
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
