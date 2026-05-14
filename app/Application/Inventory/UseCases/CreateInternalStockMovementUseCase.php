<?php

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Inventory\LowStockAlertService;
use App\Application\Support\AuditLogger;
use App\Application\Support\StockBalanceUpdater;
use App\Application\Support\UserNotificationService;
use App\Domain\InventoryCore\Enums\InternalStockMovementDirection;
use App\Domain\InventoryCore\Enums\InternalStockMovementPurpose;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\InternalStockMovement;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInternalStockMovementUseCase implements UseCase
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly StockBalanceUpdater $stockBalanceUpdater,
        private readonly LowStockAlertService $lowStockAlertService,
        private readonly UserNotificationService $userNotificationService,
    ) {
    }

    public function execute(mixed $payload = null): InternalStockMovement
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): InternalStockMovement {
            $affectedProductIds = collect((array) ($data['lines'] ?? []))
                ->pluck('product_id')
                ->filter()
                ->all();
            $beforeLowStockSnapshot = $this->lowStockAlertService->snapshotForProducts($affectedProductIds);
            $usedStockItemIds = [];

            $movement = InternalStockMovement::query()->create([
                'movement_number' => $data['movement_number'],
                'movement_date' => $data['movement_date'],
                'movement_direction' => InternalStockMovementDirection::Out,
                'purpose' => InternalStockMovementPurpose::from((string) $data['purpose']),
                'status' => 'POSTED',
                'remarks' => $data['remarks'] ?? null,
                'created_by' => (int) $data['created_by'],
            ]);

            foreach ((array) $data['lines'] as $line) {
                $product = Product::query()->findOrFail((int) $line['product_id']);
                $qty = (int) $line['qty'];

                if ($product->requiresSerialNumber()) {
                    $stockItemIds = array_values(array_map('intval', Arr::wrap($line['stock_item_ids'] ?? [])));

                    if (count($stockItemIds) !== count(array_unique($stockItemIds))) {
                        throw ValidationException::withMessages([
                            'lines' => ['Duplicate stock_item_ids are not allowed in the same line.'],
                        ]);
                    }

                    $duplicateAcrossLines = array_values(array_intersect($stockItemIds, $usedStockItemIds));
                    if ($duplicateAcrossLines !== []) {
                        throw ValidationException::withMessages([
                            'lines' => ['Duplicate stock_item_ids are not allowed across lines in one internal movement request.'],
                        ]);
                    }

                    if (count($stockItemIds) !== $qty) {
                        throw ValidationException::withMessages([
                            'lines' => ['Serialized products require stock_item_ids count to match qty.'],
                        ]);
                    }

                    $stockItems = StockItem::query()
                        ->whereIn('id', $stockItemIds)
                        ->where('product_id', $product->id)
                        ->where('current_status', StockItemStatus::InStock->value)
                        ->where('is_available', true)
                        ->where('qc_status', StockItemQcStatus::Passed->value)
                        ->lockForUpdate()
                        ->get();

                    if ($stockItems->count() !== count($stockItemIds)) {
                        throw ValidationException::withMessages([
                            'lines' => ['Some serials are invalid for this line, not currently IN_STOCK, or have not passed QC.'],
                        ]);
                    }

                    $usedStockItemIds = array_values(array_merge($usedStockItemIds, $stockItemIds));

                    foreach ($stockItems as $stockItem) {
                        $lineModel = $movement->lines()->create([
                            'product_id' => $product->id,
                            'stock_item_id' => $stockItem->id,
                            'qty' => 1,
                            'remarks' => $line['remarks'] ?? null,
                        ]);

                        $stockItem->update([
                            'current_status' => StockItemStatus::InternalUse,
                            'is_available' => false,
                            'last_movement_at' => now(),
                        ]);

                        StockMovement::query()->create([
                            'movement_datetime' => now(),
                            'product_id' => $product->id,
                            'stock_item_id' => $stockItem->id,
                            'movement_type' => MovementType::InternalUseOut,
                            'reference_table' => 'internal_stock_movement_lines',
                            'reference_id' => (int) $lineModel->id,
                            'qty_in' => 0,
                            'qty_out' => 1,
                            'from_status' => StockItemStatus::InStock->value,
                            'to_status' => StockItemStatus::InternalUse->value,
                            'performed_by' => (int) $data['created_by'],
                            'remarks' => $line['remarks'] ?? null,
                        ]);
                    }

                    continue;
                }

                $movementTotals = StockMovement::query()
                    ->where('product_id', $product->id)
                    ->whereNull('stock_item_id')
                    ->selectRaw("COALESCE(SUM(CASE WHEN to_status = 'IN_STOCK' THEN qty_in ELSE 0 END), 0) as qty_in_stock_in")
                    ->selectRaw("COALESCE(SUM(CASE WHEN from_status = 'IN_STOCK' THEN qty_out ELSE 0 END), 0) as qty_in_stock_out")
                    ->lockForUpdate()
                    ->first();

                $availableQty = max(
                    (int) ($movementTotals->qty_in_stock_in ?? 0) - (int) ($movementTotals->qty_in_stock_out ?? 0),
                    0,
                );

                if ($availableQty < $qty) {
                    throw ValidationException::withMessages([
                        'lines' => [sprintf('Insufficient stock for product %s. Available: %d, requested: %d.', $product->product_code, $availableQty, $qty)],
                    ]);
                }

                $lineModel = $movement->lines()->create([
                    'product_id' => $product->id,
                    'stock_item_id' => null,
                    'qty' => $qty,
                    'remarks' => $line['remarks'] ?? null,
                ]);

                StockMovement::query()->create([
                    'movement_datetime' => now(),
                    'product_id' => $product->id,
                    'stock_item_id' => null,
                    'movement_type' => MovementType::InternalUseOut,
                    'reference_table' => 'internal_stock_movement_lines',
                    'reference_id' => (int) $lineModel->id,
                    'qty_in' => 0,
                    'qty_out' => $qty,
                    'from_status' => StockItemStatus::InStock->value,
                    'to_status' => StockItemStatus::InternalUse->value,
                    'performed_by' => (int) $data['created_by'],
                    'remarks' => $line['remarks'] ?? null,
                ]);
            }

            $this->stockBalanceUpdater->recomputeForProducts($affectedProductIds);
            $this->lowStockAlertService->notifyStatusTransitions(
                $beforeLowStockSnapshot,
                $affectedProductIds,
                (int) $data['created_by'],
            );

            $result = $movement->fresh('lines');

            $this->auditLogger->log(
                userId: (int) $data['created_by'],
                moduleName: 'Inventory',
                entityName: 'InternalStockMovement',
                entityId: (int) $result->id,
                action: AuditAction::Post,
                newValues: [
                    'movement_number' => $result->movement_number,
                    'movement_direction' => $result->movement_direction?->value,
                    'purpose' => $result->purpose?->value,
                ],
            );

            $this->userNotificationService->notifyAllActiveUsers(
                eventType: 'internal-stock-movement.posted',
                title: 'Internal stock movement posted',
                message: sprintf('Internal stock movement %s was posted.', $result->movement_number),
                data: [
                    'internal_stock_movement_id' => (int) $result->id,
                    'movement_number' => $result->movement_number,
                    'movement_direction' => $result->movement_direction?->value,
                    'purpose' => $result->purpose?->value,
                ],
                exceptUserId: (int) $data['created_by'],
                level: 'warning',
            );

            return $result;
        });
    }
}
