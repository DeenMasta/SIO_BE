<?php

namespace App\Http\Resources\Api\MasterData;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_name' => $this->customer_name,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'status' => $this->status?->value,
            'remarks' => $this->remarks,
            'invoice_history' => $this->whenLoaded('saleOrders', fn (): array => $this->saleOrders
                ->map(fn ($saleOrder): array => [
                    'sale_order_id' => $saleOrder->id,
                    'so_number' => $saleOrder->so_number,
                    'so_date' => $saleOrder->so_date,
                    'invoice_number' => $saleOrder->invoice_number,
                    'expected_delivery_date' => $saleOrder->expected_delivery_date,
                    'status' => $saleOrder->status?->value,
                    'remarks' => $saleOrder->remarks,
                    'created_at' => $saleOrder->created_at,
                    'updated_at' => $saleOrder->updated_at,
                ])
                ->values()
                ->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
