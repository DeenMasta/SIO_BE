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
            'exchange' => $this->whenLoaded('exchange', function (): ?array {
                if (! $this->exchange) {
                    return null;
                }

                return [
                    'id' => $this->exchange->id,
                    'sale_order_id' => $this->exchange->sale_order_id,
                    'replacement_stock_out_id' => $this->exchange->replacement_stock_out_id,
                    'replacement_stock_out_number' => $this->exchange->replacementStockOut?->stock_out_number,
                    'status' => $this->exchange->status?->value,
                    'lines' => $this->exchange->lines->map(fn ($line): array => [
                        'id' => $line->id,
                        'customer_return_line_id' => $line->customer_return_line_id,
                        'replacement_product_id' => $line->replacement_product_id,
                        'replacement_product_code' => $line->replacementProduct?->product_code,
                        'qty' => $line->qty,
                        'sale_order_line_id' => $line->sale_order_line_id,
                        'replacement_stock_out_line_id' => $line->replacement_stock_out_line_id,
                    ])->values(),
                ];
            }),
            'created_by_user' => $this->whenLoaded('createdByUser', function (): ?array {
                if (! $this->createdByUser) {
                    return null;
                }

                return [
                    'id' => $this->createdByUser->id,
                    'name' => $this->createdByUser->name,
                    'email' => $this->createdByUser->email,
                ];
            }),
            'pic' => $this->whenLoaded('createdByUser', fn (): ?array => $this->createdByUser ? [
                'id' => $this->createdByUser->id,
                'name' => $this->createdByUser->name,
                'email' => $this->createdByUser->email,
                'role' => $this->createdByUser->role?->value,
                'status' => $this->createdByUser->status?->value,
            ] : null),
            'lines' => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'original_stock_out_line_id' => $line->original_stock_out_line_id,
                'product_id' => $line->product_id,
                'stock_item_id' => $line->stock_item_id,
                'serial_number' => $line->stockItem?->serial_number,
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
