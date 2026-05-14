<?php

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Inventory\LowStockAlertService;
use App\Application\Support\AuditLogger;
use App\Application\Support\StockBalanceUpdater;
use App\Application\Support\UserNotificationService;
use App\Domain\InventoryCore\Enums\InternalStockMovementDirection;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\InternalStockMovement;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnInternalStockMovementUseCase implements UseCase
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
        /** @var InternalStockMovement $originalMovement */
        $originalMovement = $data['internal_stock_movement'];

        return DB::transaction(function () use ($data, $originalMovement): InternalStockMovement {
            $originalMovement = InternalStockMovement::query()
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail((int) $originalMovement->id);

            if ($originalMovement->movement_direction !== InternalStockMovementDirection::Out) {
                throw ValidationException::withMessages([
                    'movement' => ['Only OUT internal stock movements can be returned to stock.'],
                ]);
            }

            $affectedProductIds = collect((array) ($data['lines'] ?? []))
                ->pluck('product_id')
                ->filter()
                ->all();
            $beforeLowStockSnapshot = $this->lowStockAlertService->snapshotForProducts($affectedProductIds);
            $usedStockItemIds = [];

            $movement = InternalStockMovement::query()->create([
                'movement_number' => $data['movement_number'],
                'movement_date' => $data['movement_date'],
                'movement_direction' => InternalStockMovementDirection::Return,
                'purpose' => $originalMovement->purpose,
                'original_movement_id' => $originalMovement->id,
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
                            'lines' => ['Duplicate stock_item_ids are not allowed across lines in one return request.'],
                        ]);
                    }

                    if (count($stockItemIds) !== $qty) {
                        throw ValidationException::withMessages([
                            'lines' => ['Serialized products require stock_item_ids count to match qty.'],
                        ]);
                    }

                    $originalStockItemIds = $originalMovement->lines
                        ->where('product_id', $product->id)
                        ->pluck('stock_item_id')
                        ->filter()
                        ->map(fn ($id) => (int) $id)
                        ->all();

                    $missingFromOriginal = array_values(array_diff($stockItemIds, $originalStockItemIds));
                    if ($missingFromOriginal !== []) {
                        throw ValidationException::withMessages([
                            'lines' => ['Serialized return must reference items from the original internal movement.'],
                        ]);
                    }

                    $stockItems = StockItem::query()
                        ->whereIn('id', $stockItemIds)
                        ->where('product_id', $product->id)
                        ->where('current_status', StockItemStatus::InternalUse->value)
                        ->where('is_available', false)
                        ->lockForUpdate()
                        ->get();

                    if ($stockItems->count() !== count($stockItemIds)) {
                        throw ValidationException::withMessages([
                            'lines' => ['Some serials are not currently in INTERNAL_USE status and cannot be returned to stock.'],
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
                            'current_status' => StockItemStatus::InStock,
                            'is_available' => true,
                            'last_movement_at' => now(),
                        ]);

                        StockMovement::query()->create([
                            'movement_datetime' => now(),
                            'product_id' => $product->id,
                            'stock_item_id' => $stockItem->id,
                            'movement_type' => MovementType::InternalUseReturn,
                            'reference_table' => 'internal_stock_movement_lines',
                            'reference_id' => (int) $lineModel->id,
                            'qty_in' => 1,
                            'qty_out' => 0,
                            'from_status' => StockItemStatus::InternalUse->value,
                            'to_status' => StockItemStatus::InStock->value,
                            'performed_by' => (int) $data['created_by'],
                            'remarks' => $line['remarks'] ?? null,
                        ]);
                    }

                    continue;
                }

                $issuedQty = (int) $originalMovement->lines
                    ->where('product_id', $product->id)
                    ->whereNull('stock_item_id')
                    ->sum('qty');

                if ($issuedQty <= 0) {
                    throw ValidationException::withMessages([
                        'lines' => ['Return product must exist on the original internal movement.'],
                    ]);
                }

                $returnedQty = (int) InternalStockMovement::query()
                    ->where('original_movement_id', $originalMovement->id)
                    ->where('movement_direction', InternalStockMovementDirection::Return->value)
                    ->join('internal_stock_movement_lines as lines', 'lines.internal_stock_movement_id', '=', 'internal_stock_movements.id')
                    ->where('lines.product_id', $product->id)
                    ->whereNull('lines.stock_item_id')
                    ->sum('lines.qty');

                $remainingQty = max($issuedQty - $returnedQty, 0);
                if ($qty > $remainingQty) {
                    throw ValidationException::withMessages([
                        'lines' => [sprintf('Cannot return more than issued for product %s. Remaining internal-use qty: %d, requested: %d.', $product->product_code, $remainingQty, $qty)],
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
                    'movement_type' => MovementType::InternalUseReturn,
                    'reference_table' => 'internal_stock_movement_lines',
                    'reference_id' => (int) $lineModel->id,
                    'qty_in' => $qty,
                    'qty_out' => 0,
                    'from_status' => StockItemStatus::InternalUse->value,
                    'to_status' => StockItemStatus::InStock->value,
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
                    'original_movement_id' => $result->original_movement_id,
                ],
            );

            $this->userNotificationService->notifyAllActiveUsers(
                eventType: 'internal-stock-movement.returned',
                title: 'Internal stock returned',
                message: sprintf('Internal stock movement %s was returned to stock.', $result->movement_number),
                data: [
                    'internal_stock_movement_id' => (int) $result->id,
                    'movement_number' => $result->movement_number,
                    'movement_direction' => $result->movement_direction?->value,
                    'original_movement_id' => (int) $result->original_movement_id,
                ],
                exceptUserId: (int) $data['created_by'],
                level: 'success',
            );

            return $result;
        });
    }
}
