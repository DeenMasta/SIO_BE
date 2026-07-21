<?php

namespace App\Application\QcOutbound\StockOut\UseCases;

use App\Application\Inventory\LowStockAlertService;
use App\Application\Support\StockBalanceUpdater;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\SalesOutbound\Enums\SaleOrderStatus;
use App\Models\SaleOrder;
use App\Models\SaleOrderLine;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StockOut;
use App\Models\StockOutLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettleStockOutExtrasUseCase
{
    public function __construct(
        private readonly StockBalanceUpdater $stockBalanceUpdater,
        private readonly LowStockAlertService $lowStockAlertService,
    ) {
    }

    public function execute(StockOut $stockOut, array $payload, ?int $performedBy = null): StockOut
    {
        return DB::transaction(function () use ($stockOut, $payload, $performedBy): StockOut {
            $lockedStockOut = StockOut::query()
                ->with([
                    'saleOrder.lines',
                    'lines.product',
                    'lines.lineItems.stockItem',
                    'lines.lineItems.settledSaleOrderLine',
                ])
                ->lockForUpdate()
                ->findOrFail((int) $stockOut->id);

            $saleOrder = $lockedStockOut->saleOrder;
            if (! $saleOrder) {
                throw ValidationException::withMessages([
                    'sale_order_id' => ['Stock out must be linked to a sales order before settling extra items.'],
                ]);
            }

            if (! in_array($saleOrder->status, [SaleOrderStatus::Confirmed, SaleOrderStatus::Fulfilled], true)) {
                throw ValidationException::withMessages([
                    'sale_order_id' => ['Only CONFIRMED or FULFILLED sales orders can receive settled extra items.'],
                ]);
            }

            $linesById = $lockedStockOut->lines->keyBy('id');
            $reverseAffectedProductIds = [];
            $beforeLowStockSnapshot = null;
            $normalizedPayloadLines = array_values((array) ($payload['lines'] ?? []));

            if ($performedBy !== null) {
                $reverseAffectedProductIds = collect($normalizedPayloadLines)
                    ->map(fn (array $linePayload): ?int => $linesById->get((int) $linePayload['stock_out_line_id'])?->product_id)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($reverseAffectedProductIds !== []) {
                    $beforeLowStockSnapshot = $this->lowStockAlertService->snapshotForProducts($reverseAffectedProductIds);
                }
            }

            foreach ($normalizedPayloadLines as $linePayload) {
                $stockOutLine = $linesById->get((int) $linePayload['stock_out_line_id']);

                if (! $stockOutLine instanceof StockOutLine || ! $stockOutLine->is_extra) {
                    throw ValidationException::withMessages([
                        'lines' => ['Only extra stock out lines can be settled into the sales order.'],
                    ]);
                }

                $isFree = filter_var($linePayload['is_free'] ?? false, FILTER_VALIDATE_BOOL);
                $unitPrice = $isFree ? 0.0 : (float) $linePayload['unit_price'];
                $remarks = trim((string) ($linePayload['remarks'] ?? ''));

                if ($stockOutLine->product?->requiresSerialNumber()) {
                    $selectedIds = array_values(array_map('intval', (array) ($linePayload['stock_item_ids'] ?? [])));
                    $availableItems = $stockOutLine->lineItems
                        ->whereNull('settled_sale_order_line_id')
                        ->whereNull('reversed_at')
                        ->sortBy('id')
                        ->values();

                    $selectedItems = $availableItems
                        ->whereIn('stock_item_id', $selectedIds);

                    if ($selectedItems->count() !== count($selectedIds)) {
                        throw ValidationException::withMessages([
                            'lines' => ['Some selected serialized extra items are invalid, already settled, or already reversed.'],
                        ]);
                    }

                    if ($selectedItems->count() > 0) {
                        $saleOrderLine = $this->createSettledSaleOrderLine(
                            saleOrder: $saleOrder,
                            stockOutLine: $stockOutLine,
                            qty: $selectedItems->count(),
                            isFree: $isFree,
                            unitPrice: $unitPrice,
                            remarks: $remarks,
                        );

                        foreach ($selectedItems as $lineItem) {
                            $lineItem->update([
                                'settled_sale_order_line_id' => (int) $saleOrderLine->id,
                            ]);
                        }

                        $stockOutLine->increment('settled_qty', $selectedItems->count());
                    }

                    $reverseItems = $availableItems->whereNotIn('stock_item_id', $selectedIds);
                    if ($reverseItems->count() > 0) {
                        foreach ($reverseItems as $lineItem) {
                            $stockItem = $lineItem->stockItem;
                            if (! $stockItem instanceof StockItem || $stockItem->current_status !== StockItemStatus::Delivered) {
                                throw ValidationException::withMessages([
                                    'lines' => ['Only delivered serialized extra items can be returned to stock automatically.'],
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

                            if ($performedBy !== null) {
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
                                        : sprintf('Unpaid extra item returned from stock out %s during settlement.', $lockedStockOut->stock_out_number),
                                ]);
                            }
                        }

                        $stockOutLine->increment('reversed_qty', $reverseItems->count());
                    }

                    continue;
                }

                $settleQty = (int) ($linePayload['settle_qty'] ?? 0);
                $pendingQty = max((int) $stockOutLine->qty - (int) $stockOutLine->settled_qty - (int) $stockOutLine->reversed_qty, 0);

                if ($settleQty < 0 || $settleQty > $pendingQty) {
                    throw ValidationException::withMessages([
                        'lines' => [sprintf(
                            'Extra line %d can settle between 0 and %d unit(s).',
                            (int) $stockOutLine->id,
                            $pendingQty,
                        )],
                    ]);
                }

                if ($settleQty > 0) {
                    $this->createSettledSaleOrderLine(
                        saleOrder: $saleOrder,
                        stockOutLine: $stockOutLine,
                        qty: $settleQty,
                        isFree: $isFree,
                        unitPrice: $unitPrice,
                        remarks: $remarks,
                    );

                    $stockOutLine->increment('settled_qty', $settleQty);
                }

                $reverseQty = $pendingQty - $settleQty;
                if ($reverseQty > 0) {
                    if ($performedBy !== null) {
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
                                : sprintf('Unpaid extra qty returned from stock out %s during settlement.', $lockedStockOut->stock_out_number),
                        ]);
                    }

                    $stockOutLine->increment('reversed_qty', $reverseQty);
                }
            }

            if ($performedBy !== null && $reverseAffectedProductIds !== []) {
                $this->stockBalanceUpdater->recomputeForProducts($reverseAffectedProductIds);
                $this->lowStockAlertService->notifyStatusTransitions(
                    $beforeLowStockSnapshot ?? [],
                    $reverseAffectedProductIds,
                    $performedBy,
                );
            }

            $saleOrder->refresh();
            $hasUnfulfilled = $saleOrder->lines()->get()->contains(
                fn (SaleOrderLine $line): bool => (int) $line->fulfilled_qty < (int) $line->ordered_qty,
            );

            $saleOrder->status = $hasUnfulfilled ? SaleOrderStatus::Confirmed : SaleOrderStatus::Fulfilled;
            $saleOrder->save();

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

    private function createSettledSaleOrderLine(
        SaleOrder $saleOrder,
        StockOutLine $stockOutLine,
        int $qty,
        bool $isFree,
        float $unitPrice,
        string $remarks,
    ): SaleOrderLine {
        return $saleOrder->lines()->create([
            'product_id' => (int) $stockOutLine->product_id,
            'source_stock_out_line_id' => (int) $stockOutLine->id,
            'ordered_qty' => $qty,
            'fulfilled_qty' => $qty,
            'is_free' => $isFree,
            'unit_price' => $unitPrice,
            'subtotal' => $qty * $unitPrice,
            'remarks' => $remarks !== ''
                ? $remarks
                : sprintf('Settled from extra items on stock out %s.', $stockOutLine->stockOut?->stock_out_number ?? $stockOutLine->stock_out_id),
        ]);
    }
}
