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
            'stock_in_id' => $this->stock_in_id,
            'return_date' => $this->return_date,
            'status' => $this->status?->value,
            'remarks' => $this->remarks,
            'created_by' => $this->created_by,
            'lines' => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'stock_item_id' => $line->stock_item_id,
                'qty' => $line->qty,
                'reason_for_return' => $line->reason_for_return,
                'remarks' => $line->remarks,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
