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
            'repair_flow' => $this->repair_flow?->value,
            'issue_description' => $this->issue_description,
            'repair_status' => $this->repair_status?->value,
            'returned_to_customer_date' => $this->returned_to_customer_date,
            'return_tracking_number' => $this->return_tracking_number,
            'remarks' => $this->remarks,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'customer' => $this->whenLoaded('customer', function (): array {
                return [
                    'id' => $this->customer->id,
                    'name' => $this->customer->customer_name,
                    'contactPerson' => $this->customer->contact_person,
                    'phone' => $this->customer->phone,
                    'email' => $this->customer->email,
                    'address' => $this->customer->address,
                    'status' => $this->customer->status?->value,
                    'remarks' => $this->customer->remarks,
                ];
            }),
            'stock_item' => $this->whenLoaded('stockItem', function (): array {
                return [
                    'id' => $this->stockItem->id,
                    'serial_number' => $this->stockItem->serial_number,
                    'serial_source' => $this->stockItem->serial_source?->value,
                    'current_status' => $this->stockItem->current_status?->value,
                    'qc_status' => $this->stockItem->qc_status?->value,
                    'is_available' => $this->stockItem->is_available,
                    'remarks' => $this->stockItem->remarks,
                    'product' => $this->stockItem->relationLoaded('product') && $this->stockItem->product
                        ? [
                            'id' => $this->stockItem->product->id,
                            'code' => $this->stockItem->product->product_code,
                            'name' => $this->stockItem->product->product_name,
                            'product_type' => $this->stockItem->product->product_type,
                        ]
                        : null,
                ];
            }),
            'created_by_user' => $this->whenLoaded('createdByUser', function (): array {
                return [
                    'id' => $this->createdByUser->id,
                    'name' => $this->createdByUser->name,
                    'email' => $this->createdByUser->email,
                    'role' => $this->createdByUser->role?->value,
                    'status' => $this->createdByUser->status?->value,
                ];
            }),
            'status_history' => $this->whenLoaded('statusHistory', function (): array {
                return $this->statusHistory->map(function ($history): array {
                    return [
                        'id' => $history->id,
                        'from_status' => $history->from_status,
                        'to_status' => $history->to_status,
                        'remarks' => $history->remarks,
                        'changed_at' => $history->changed_at,
                        'changed_by' => $history->changed_by,
                        'changed_by_user' => $history->relationLoaded('changedBy') && $history->changedBy
                            ? [
                                'id' => $history->changedBy->id,
                                'name' => $history->changedBy->name,
                                'email' => $history->changedBy->email,
                                'role' => $history->changedBy->role?->value,
                                'status' => $history->changedBy->status?->value,
                            ]
                            : null,
                    ];
                })->all();
            }),
        ];
    }
}
