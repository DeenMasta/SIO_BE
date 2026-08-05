<?php

namespace App\Http\Resources\Api\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MissingItemReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'report_number' => $this->report_number, 'stocktake_id' => $this->stocktake_id, 'stocktake_number' => $this->stocktake?->stocktake_number,
            'product_id' => $this->product_id, 'product_code' => $this->product?->product_code, 'product_name' => $this->product?->product_name,
            'stock_item_id' => $this->stock_item_id, 'serial_number' => $this->stockItem?->serial_number, 'missing_qty' => $this->missing_qty,
            'status' => $this->status?->value, 'resolution_type' => $this->resolution_type, 'stock_out_id' => $this->stock_out_id, 'stock_out_number' => $this->stockOut?->stock_out_number,
            'investigation_notes' => $this->investigation_notes, 'resolution_notes' => $this->resolution_notes, 'reported_by' => $this->reported_by, 'reported_by_name' => $this->reportedByUser?->name,
            'resolved_by' => $this->resolved_by, 'resolved_by_name' => $this->resolvedByUser?->name, 'resolved_at' => $this->resolved_at, 'created_at' => $this->created_at];
    }
}
