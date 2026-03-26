<?php

namespace App\Http\Resources\Api\ExceptionsReturns;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepairResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'repair_transaction_number' => $this->repair_transaction_number,
            'repair_date' => $this->repair_date,
            'stock_item_id' => $this->stock_item_id,
            'customer_id' => $this->customer_id,
            'issue_description' => $this->issue_description,
            'repair_status' => $this->repair_status?->value,
            'remarks' => $this->remarks,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
