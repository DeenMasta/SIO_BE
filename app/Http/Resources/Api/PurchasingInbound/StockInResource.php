<?php

namespace App\Http\Resources\Api\PurchasingInbound;

use App\Domain\ExceptionsReturns\Enums\ExceptionTransactionStatus;
use App\Domain\InventoryCore\Enums\StockItemStatus;
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
            'supplier_name' => $this->supplier?->supplier_name,
            'stock_in_pic_id' => $this->stock_in_pic_id,
            'status' => $this->status?->value === 'POSTED'
                ? 'RECEIVED'
                : $this->status?->value,
            'remarks' => $this->remarks,
            'lines' => $this->lines->map(function ($line): array {
                $postedReturnedQty = $line->returnToSupplierLines
                    ->filter(fn ($returnLine) => $returnLine->returnToSupplier?->status === ExceptionTransactionStatus::Posted)
                    ->sum('qty');

                $availableSerializedQty = $line->stockItems
                    ->filter(fn ($item) => $item->is_available && in_array($item->current_status?->value, [
                        StockItemStatus::InStock->value,
                        StockItemStatus::Received->value,
                    ], true))
                    ->count();

                $hasSerializedItems = $line->stockItems->isNotEmpty();

                return [
                    'id' => $line->id,
                    'purchase_order_line_id' => $line->purchase_order_line_id,
                    'product_id' => $line->product_id,
                    'product_code' => $line->product?->product_code,
                    'product_name' => $line->product?->product_name,
                    'product_type' => $line->product?->product_type?->value,
                    'received_qty' => $line->received_qty,
                    'returned_qty' => $postedReturnedQty,
                    'returnable_qty' => $hasSerializedItems
                        ? $availableSerializedQty
                        : max((int) $line->received_qty - (int) $postedReturnedQty, 0),
                    'remarks' => $line->remarks,
                    'stock_items' => $line->stockItems->map(fn ($item): array => [
                        'id' => $item->id,
                        'serial_number' => $item->serial_number,
                        'factory_serial_number' => $item->factory_serial_number,
                        'serial_source' => $item->serial_source?->value,
                        'current_status' => $item->current_status?->value,
                        'is_available' => (bool) $item->is_available,
                    ]),
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
