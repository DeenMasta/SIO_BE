<?php

namespace App\Http\Resources\Api\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StocktakeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'stocktake_number' => $this->stocktake_number, 'stocktake_date' => $this->stocktake_date?->format('Y-m-d'),
            'status' => $this->status?->value, 'remarks' => $this->remarks, 'created_by' => $this->created_by, 'submitted_by' => $this->submitted_by, 'submitted_at' => $this->submitted_at,
            'created_by_user' => $this->user($this->relationLoaded('createdByUser') ? $this->createdByUser : null),
            'submitted_by_user' => $this->user($this->relationLoaded('submittedByUser') ? $this->submittedByUser : null),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id, 'product_id' => $line->product_id, 'product_code' => $line->product?->product_code, 'product_name' => $line->product?->product_name,
                'requires_serial_number' => (bool) $line->product?->requires_serial_number, 'expected_qty' => $line->expected_qty, 'counted_qty' => $line->counted_qty,
                'variance_qty' => $line->variance_qty, 'remarks' => $line->remarks,
                'items' => $line->relationLoaded('items') ? $line->items->map(fn ($item) => ['stock_item_id' => $item->stock_item_id, 'serial_number' => $item->serial_number, 'is_counted' => $item->is_counted])->values() : [],
            ])->values()),
            'missing_reports' => $this->whenLoaded('missingReports', fn () => MissingItemReportResource::collection($this->missingReports)),
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }

    private function user(mixed $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
