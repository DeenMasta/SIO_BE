<?php

namespace App\Application\ReportingAudit\Reports\Services;

use App\Domain\InventoryCore\Enums\MovementType;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * SerialTracingService provides complete traceability for serialized items.
 * Returns full lifecycle: receive → QC → out → return (if applicable)
 */
class SerialTracingService
{
    /**
     * Get full traceability for a serial number.
     *
     * Returns array with:
     * - stock_item: The serialized item details
     * - product: Product information
     * - movements: Chronological list of all movements
     *
     * @return array|null Returns null if serial not found
     */
    public function getSerialTrace(string $serialNumber): ?array
    {
        $stockItem = StockItem::query()
            ->with(['stockInLine.product'])
            ->where('serial_number', $serialNumber)
            ->first();

        if (! $stockItem) {
            return null;
        }

        // Ensure relationships exist
        if (! $stockItem->stockInLine || ! $stockItem->stockInLine->product) {
            return null;
        }

        $movements = StockMovement::query()
            ->where('stock_item_id', $stockItem->id)
            ->orderBy('movement_datetime', 'asc')
            ->get();

        return [
            'serial_number' => $serialNumber,
            'product' => [
                'id' => $stockItem->stockInLine->product->id,
                'product_code' => $stockItem->stockInLine->product->product_code,
                'product_name' => $stockItem->stockInLine->product->product_name,
                'product_type' => $stockItem->stockInLine->product->product_type,
            ],
            'current_status' => $stockItem->current_status?->value,
            'is_available' => $stockItem->is_available,
            'created_at' => $stockItem->created_at?->toIso8601String(),
            'movements' => $this->formatMovements($movements),
        ];
    }

    /**
     * Format movements for API response with transaction details.
     */
    private function formatMovements(Collection $movements): array
    {
        return $movements->map(function (StockMovement $movement) {
            return [
                'id' => $movement->id,
                'movement_datetime' => $movement->movement_datetime,
                'movement_type' => $movement->movement_type?->value,
                'from_status' => $movement->from_status,
                'to_status' => $movement->to_status,
                'qty_in' => $movement->qty_in,
                'qty_out' => $movement->qty_out,
                'reference_table' => $movement->reference_table,
                'reference_id' => $movement->reference_id,
                'performed_by' => $movement->performed_by,
                'remarks' => $movement->remarks,
                'created_at' => $movement->created_at,
            ];
        })->toArray();
    }
}
