<?php

namespace App\Http\Resources\Api\PurchasingInbound;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'po_date' => $this->po_date,
            'supplier_id' => $this->supplier_id,
            'expected_delivery_date' => $this->expected_delivery_date,
            'status' => $this->status?->value,
            'created_by' => $this->created_by,
            'remarks' => $this->remarks,
            'lines' => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'ordered_qty' => $line->ordered_qty,
                'received_qty' => $line->received_qty,
                'unit_price' => (string) $line->unit_price,
                'subtotal' => (string) $line->subtotal,
                'remarks' => $line->remarks,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
