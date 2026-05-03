<?php

namespace App\Http\Resources\Api\MasterData;

use App\Models\ProductCondition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_code' => $this->product_code,
            'product_name' => $this->product_name,
            'product_model' => $this->product_model,
            'product_type' => $this->product_type?->value,
            'requires_serial_number' => (bool) $this->requires_serial_number,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->supplier ? [
                'id' => $this->supplier->id,
                'supplier_code' => $this->supplier->supplier_code,
                'supplier_name' => $this->supplier->supplier_name,
            ] : null,
            'selling_price' => (string) $this->selling_price,
            'uom' => $this->uom,
            'reorder_level' => $this->reorder_level,
            'remarks' => $this->remarks,
            'status' => $this->status?->value,
            'accessories' => $this->accessories->map(fn ($accessory): array => [
                'id' => $accessory->id,
                'accessory_name' => $accessory->accessory_name,
                'quantity' => $accessory->quantity,
                'remarks' => $accessory->remarks,
            ]),
            'conditions' => $this->conditions->map(fn ($condition): array => [
                'id' => $condition->id,
                'condition_name' => $condition->condition_name,
            ]),
            'condition_options' => ProductCondition::availableConditions(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
