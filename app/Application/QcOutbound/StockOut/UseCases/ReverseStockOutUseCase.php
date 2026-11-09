<?php

namespace App\Application\QcOutbound\StockOut\UseCases;

use App\Application\Inventory\LowStockAlertService;
use App\Application\Support\AuditLogger;
use App\Application\Support\StockBalanceUpdater;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\QcOutbound\Enums\StockOutStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Domain\SalesOutbound\Enums\SaleOrderStatus;
use App\Models\SaleOrder;
use App\Models\SaleOrderLine;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StockOut;
use App\Models\StockOutLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReverseStockOutUseCase
{
    public function __construct(
        private readonly StockBalanceUpdater $stockBalanceUpdater,
        private readonly LowStockAlertService $lowStockAlertService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function execute(StockOut $stockOut, array $payload, int $performedBy): StockOut
    {
        return DB::transaction(function () use ($stockOut, $payload, $performedBy): StockOut {
            $lockedStockOut = StockOut::query()
                ->with(['saleOrder.lines', 'lines.product', 'lines.lineItems.stockItem'])
                ->lockForUpdate()
                ->findOrFail((int) $stockOut->id);

            if ($lockedStockOut->status === StockOutStatus::Cancelled) {
                throw ValidationException::withMessages(['stock_out' => ['This stock out has already been fully reversed.']]);
            }

            $saleOrder = $lockedStockOut->sale_order_id
                ? SaleOrder::query()->lockForUpdate()->findOrFail((int) $lockedStockOut->sale_order_id)
                : null;

            $lines = $lockedStockOut->lines->keyBy('id');
            $affectedProductIds = [];
            $reversed = [];
            $beforeLowStockSnapshot = $this->lowStockAlertService->snapshotForProducts(
                $lockedStockOut->lines->pluck('product_id')->all(),
            );

            foreach ((array) $payload['lines'] as $linePayload) {
                $line = $lines->get((int) $linePayload['stock_out_line_id']);
                if (! $line instanceof StockOutLine || (int) $line->settled_qty > 0) {
                    throw ValidationException::withMessages(['lines' => ['Settled or invalid stock out lines cannot be reversed.']]);
                }

                $affectedProductIds[] = (int) $line->product_id;
                $remaining = max((int) $line->qty - (int) $line->reversed_qty, 0);
                if ($remaining < 1) {
                    throw ValidationException::withMessages(['lines' => ['A selected stock out line is already fully reversed.']]);
                }

                if ($line->product?->requiresSerialNumber()) {
                    $selectedIds = array_values(array_map('intval', (array) ($linePayload['stock_item_ids'] ?? [])));
                    $items = $line->lineItems->whereNull('reversed_at')->whereIn('stock_item_id', $selectedIds);
                    if ($selectedIds === [] || $items->count() !== count($selectedIds)) {
                        throw ValidationException::withMessages(['lines' => ['Select only active dispatched serial numbers.']]);
                    }

                    foreach ($items as $item) {
                        $stockItem = $item->stockItem;
                        if (! $stockItem instanceof StockItem || $stockItem->current_status !== StockItemStatus::Delivered) {
                            throw ValidationException::withMessages(['lines' => ['Only delivered serial items can be reversed.']]);
                        }

                        $stockItem->update(['current_status' => StockItemStatus::InStock, 'is_available' => true, 'last_movement_at' => now()]);
                        $item->update(['reversed_at' => now()]);
                        StockMovement::query()->create([
                            'movement_datetime' => now(), 'product_id' => $stockItem->product_id, 'stock_item_id' => $stockItem->id,
                            'movement_type' => MovementType::StockOutReversal, 'reference_table' => 'stock_out_line_items', 'reference_id' => $item->id,
                            'qty_in' => 1, 'qty_out' => 0, 'from_status' => StockItemStatus::Delivered->value, 'to_status' => StockItemStatus::InStock->value,
                            'performed_by' => $performedBy, 'remarks' => $payload['remarks'],
                        ]);
                    }
                    $quantity = count($selectedIds);
                } else {
                    $quantity = (int) ($linePayload['reverse_qty'] ?? 0);
                    if ($quantity < 1 || $quantity > $remaining) {
                        throw ValidationException::withMessages(['lines' => ["Line {$line->id} can reverse between 1 and {$remaining} unit(s)."]]);
                    }
                    StockMovement::query()->create([
                        'movement_datetime' => now(), 'product_id' => $line->product_id, 'stock_item_id' => null,
                        'movement_type' => MovementType::StockOutReversal, 'reference_table' => 'stock_out_lines', 'reference_id' => $line->id,
                        'qty_in' => $quantity, 'qty_out' => 0, 'from_status' => StockItemStatus::Delivered->value, 'to_status' => StockItemStatus::InStock->value,
                        'performed_by' => $performedBy, 'remarks' => $payload['remarks'],
                    ]);
                }

                $line->increment('reversed_qty', $quantity);
                if ($line->sale_order_line_id) {
                    SaleOrderLine::query()->whereKey($line->sale_order_line_id)->decrement('fulfilled_qty', $quantity);
                }
                $reversed[] = ['line_id' => (int) $line->id, 'qty' => $quantity];
            }

            $affectedProductIds = array_values(array_unique($affectedProductIds));
            $this->stockBalanceUpdater->recomputeForProducts($affectedProductIds);

            if ($saleOrder) {
                $hasUnfulfilled = $saleOrder->lines()->get()->contains(
                    fn (SaleOrderLine $line): bool => (int) $line->fulfilled_qty < (int) $line->ordered_qty,
                );
                $saleOrder->update(['status' => $hasUnfulfilled ? SaleOrderStatus::Confirmed : SaleOrderStatus::Fulfilled]);
            }

            $allReversed = $lockedStockOut->lines()->get()->every(
                fn (StockOutLine $line): bool => (int) $line->reversed_qty >= (int) $line->qty,
            );
            if ($allReversed) {
                $lockedStockOut->update(['status' => StockOutStatus::Cancelled]);
            }

            $this->lowStockAlertService->notifyStatusTransitions(
                $beforeLowStockSnapshot, $affectedProductIds, $performedBy,
            );
            $this->auditLogger->log($performedBy, 'QcOutbound', 'StockOut', (int) $lockedStockOut->id, AuditAction::Cancel,
                newValues: ['remarks' => $payload['remarks'], 'reversed_lines' => $reversed]);

            return $lockedStockOut->fresh(['pic', 'saleOrder', 'lines.product', 'lines.saleOrderLine', 'lines.settledSaleOrderLines', 'lines.lineItems.stockItem', 'lines.lineItems.settledSaleOrderLine']);
        });
    }
}
