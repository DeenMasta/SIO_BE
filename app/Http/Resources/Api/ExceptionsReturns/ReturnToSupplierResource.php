<?php

namespace App\Http\Resources\Api\ExceptionsReturns;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnToSupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rts_transaction_number' => $this->rts_transaction_number,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->supplier_name,
            'stock_in_id' => $this->stock_in_id,
            'stock_in_number' => $this->stockIn?->stock_in_number,
            'return_date' => $this->return_date,
            'status' => $this->status?->value,
            'remarks' => $this->remarks,
            'created_by' => $this->created_by,
            'lines' => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'stock_in_line_id' => $line->stock_in_line_id,
                'product_id' => $line->product_id,
                'product_code' => $line->product?->product_code ?? $line->stockInLine?->product?->product_code,
                'product_name' => $line->product?->product_name ?? $line->stockInLine?->product?->product_name,
                'stock_item_id' => $line->stock_item_id,
                'serial_number' => $line->stockItem?->serial_number,
                'qty' => $line->qty,
                'reason_for_return' => $line->reason_for_return,
                'remarks' => $line->remarks,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
