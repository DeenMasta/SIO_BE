<?php

namespace App\Http\Resources\Api\SalesOutbound;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuickStockOutResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'qso_number' => $this->qso_number,
            'qso_date' => $this->qso_date?->format('Y-m-d'),
            'customer_id' => $this->customer_id,
            'stock_out_id' => $this->stock_out_id,
            'converted_sale_order_id' => $this->converted_sale_order_id,
            'customer' => $this->whenLoaded('customer'),
            'status' => $this->status,
            'remarks' => $this->remarks,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('createdBy'),
            'pic' => $this->whenLoaded('createdBy', fn (): ?array => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
                'email' => $this->createdBy->email,
                'role' => $this->createdBy->role?->value,
                'status' => $this->createdBy->status?->value,
            ] : null),
            'stock_out' => $this->whenLoaded('stockOut'),
            'sale_order' => $this->whenLoaded('convertedSaleOrder'),
            'lines' => QuickStockOutLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
