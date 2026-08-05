<?php

namespace App\Http\Resources\Api\ExceptionsReturns;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerExchangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_return_id' => $this->customer_return_id,
            'sale_order_id' => $this->sale_order_id,
            'sale_order_number' => $this->saleOrder?->so_number,
            'replacement_stock_out_id' => $this->replacement_stock_out_id,
            'replacement_stock_out_number' => $this->replacementStockOut?->stock_out_number,
            'status' => $this->status?->value,
            'remarks' => $this->remarks,
            'created_by' => $this->created_by,
            'lines' => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'customer_return_line_id' => $line->customer_return_line_id,
                'replacement_product_id' => $line->replacement_product_id,
                'replacement_product_code' => $line->replacementProduct?->product_code,
                'replacement_product_name' => $line->replacementProduct?->product_name,
                'qty' => $line->qty,
                'sale_order_line_id' => $line->sale_order_line_id,
                'replacement_stock_out_line_id' => $line->replacement_stock_out_line_id,
                'remarks' => $line->remarks,
            ])->values(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
