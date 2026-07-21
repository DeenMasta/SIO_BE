<?php

namespace App\Application\QcOutbound\StockOut\UseCases;

use App\Application\Inventory\LowStockAlertService;
use App\Application\Support\StockBalanceUpdater;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StockOut;
use App\Models\StockOutLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReverseStockOutExtrasUseCase
{
    public function __construct(
        private readonly StockBalanceUpdater $stockBalanceUpdater,
        private readonly LowStockAlertService $lowStockAlertService,
    ) {
    }

    public function execute(StockOut $stockOut, array $payload, int $performedBy): StockOut
    {
        return DB::transaction(function () use ($stockOut, $payload, $performedBy): StockOut {
            $lockedStockOut = StockOut::query()
                ->with([
                    'lines.product',
                    'lines.lineItems.stockItem',
                    'lines.lineItems.settledSaleOrderLine',
                ])
                ->lockForUpdate()
                ->findOrFail((int) $stockOut->id);

            $affectedProductIds = collect((array) ($payload['lines'] ?? []))
                ->pluck('stock_out_line_id')
                ->map(fn (mixed $lineId): ?int => $lockedStockOut->lines->firstWhere('id', (int) $lineId)?->product_id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $beforeLowStockSnapshot = $this->lowStockAlertService->snapshotForProducts($affectedProductIds);

            foreach ((array) ($payload['lines'] ?? []) as $linePayload) {
                $stockOutLine = $lockedStockOut->lines->firstWhere('id', (int) $linePayload['stock_out_line_id']);

                if (! $stockOutLine instanceof StockOutLine || ! $stockOutLine->is_extra) {
                    throw ValidationException::withMessages([
                        'lines' => ['Only extra stock out lines can be reversed from stock out detail.'],
                    ]);
                }

                $remarks = trim((string) ($linePayload['remarks'] ?? ''));
                $isSerialized = $stockOutLine->product?->requiresSerialNumber();

                if ($isSerialized) {
                    $selectedIds = array_values(array_map('intval', (array) ($linePayload['stock_item_ids'] ?? [])));

                    if ($selectedIds === []) {
                        throw ValidationException::withMessages([
                            'lines' => ['Serialized extra items require selected stock_item_ids for reverse.'],
                        ]);
                    }

                    $availableItems = $stockOutLine->lineItems
                        ->whereNull('settled_sale_order_line_id')
                        ->whereNull('reversed_at')
                        ->whereIn('stock_item_id', $selectedIds);

                    if ($availableItems->count() !== count($selectedIds)) {
                        throw ValidationException::withMessages([
                            'lines' => ['Some selected serialized extra items are invalid, already settled, or already reversed.'],
                        ]);
                    }

                    foreach ($availableItems as $lineItem) {
                        $stockItem = $lineItem->stockItem;
                        if (! $stockItem instanceof StockItem || $stockItem->current_status !== StockItemStatus::Delivered) {
                            throw ValidationException::withMessages([
                                'lines' => ['Only delivered serialized extra items can be returned to stock.'],
                            ]);
                        }

                        $stockItem->update([
                            'current_status' => StockItemStatus::InStock,
                            'is_available' => true,
                            'last_movement_at' => now(),
                        ]);

                        $lineItem->update([
                            'reversed_at' => now(),
                        ]);

                        StockMovement::query()->create([
                            'movement_datetime' => now(),
                            'product_id' => (int) $stockItem->product_id,
                            'stock_item_id' => (int) $stockItem->id,
                            'movement_type' => MovementType::ExtraItemReturn,
                            'reference_table' => 'stock_out_line_items',
                            'reference_id' => (int) $lineItem->id,
                            'qty_in' => 1,
                            'qty_out' => 0,
                            'from_status' => StockItemStatus::Delivered->value,
                            'to_status' => StockItemStatus::InStock->value,
                            'performed_by' => $performedBy,
                            'remarks' => $remarks !== ''
                                ? $remarks
                                : sprintf('Extra return from stock out %s.', $lockedStockOut->stock_out_number),
                        ]);
                    }

                    $stockOutLine->increment('reversed_qty', count($selectedIds));

                    continue;
                }

                $reverseQty = (int) ($linePayload['reverse_qty'] ?? 0);
                $pendingQty = max((int) $stockOutLine->qty - (int) $stockOutLine->settled_qty - (int) $stockOutLine->reversed_qty, 0);

                if ($reverseQty < 1 || $reverseQty > $pendingQty) {
                    throw ValidationException::withMessages([
                        'lines' => [sprintf(
                            'Extra line %d can reverse between 1 and %d unit(s).',
                            (int) $stockOutLine->id,
                            $pendingQty,
                        )],
                    ]);
                }

                StockMovement::query()->create([
                    'movement_datetime' => now(),
                    'product_id' => (int) $stockOutLine->product_id,
                    'stock_item_id' => null,
                    'movement_type' => MovementType::ExtraItemReturn,
                    'reference_table' => 'stock_out_lines',
                    'reference_id' => (int) $stockOutLine->id,
                    'qty_in' => $reverseQty,
                    'qty_out' => 0,
                    'from_status' => StockItemStatus::Delivered->value,
                    'to_status' => StockItemStatus::InStock->value,
                    'performed_by' => $performedBy,
                    'remarks' => $remarks !== ''
                        ? $remarks
                        : sprintf('Extra return from stock out %s.', $lockedStockOut->stock_out_number),
                ]);

                $stockOutLine->increment('reversed_qty', $reverseQty);
            }

            $this->stockBalanceUpdater->recomputeForProducts($affectedProductIds);
            $this->lowStockAlertService->notifyStatusTransitions(
                $beforeLowStockSnapshot,
                $affectedProductIds,
                $performedBy,
            );

            return $lockedStockOut->fresh([
                'saleOrder',
                'lines.product',
                'lines.saleOrderLine',
                'lines.settledSaleOrderLines',
                'lines.lineItems.stockItem',
                'lines.lineItems.settledSaleOrderLine',
            ]);
        });
    }
}
