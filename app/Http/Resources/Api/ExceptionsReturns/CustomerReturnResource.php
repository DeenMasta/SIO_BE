<?php

namespace App\Http\Resources\Api\ExceptionsReturns;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_transaction_number' => $this->return_transaction_number,
            'return_date' => $this->return_date,
            'customer_id' => $this->customer_id,
            'original_invoice_number' => $this->original_invoice_number,
            'original_stock_out_id' => $this->original_stock_out_id,
            'status' => $this->status?->value,
            'remarks' => $this->remarks,
            'created_by' => $this->created_by,
            'lines' => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'original_stock_out_line_id' => $line->original_stock_out_line_id,
                'product_id' => $line->product_id,
                'stock_item_id' => $line->stock_item_id,
                'qty' => $line->qty,
                'reason_for_return' => $line->reason_for_return,
                'condition_on_return' => $line->condition_on_return,
                'next_action' => $line->next_action,
                'remarks' => $line->remarks,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
