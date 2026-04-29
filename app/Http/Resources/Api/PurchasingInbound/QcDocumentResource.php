<?php

namespace App\Http\Resources\Api\PurchasingInbound;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QcDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'date' => $this->date?->format('Y-m-d'),
            'pic_id' => $this->pic_id,
            'pic_name' => $this->whenLoaded('pic', fn () => $this->pic->name),
            'stock_in_id' => $this->stock_in_id,
            'status' => $this->status,
            'remarks' => $this->remarks,
            'checks_count' => $this->checks_count,
            'lines' => $this->whenLoaded('checks', function () {
                return $this->checks->map(fn ($check) => [
                    'id' => $check->id,
                    'stock_item_id' => $check->stock_item_id,
                    'serial_number' => $check->stockItem?->serial_number,
                    'product_id' => $check->stockItem?->product_id,
                    'product_name' => $check->stockItem?->product?->product_name,
                    'result' => $check->result?->value,
                    'checked_conditions' => $check->checked_conditions,
                    'checked_accessories' => $check->checked_accessories,
                    'remarks' => $check->remarks,
                ]);
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
