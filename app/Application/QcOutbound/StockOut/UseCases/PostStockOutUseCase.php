<?php

namespace App\Application\QcOutbound\StockOut\UseCases;

use App\Application\Contracts\Repositories\StockOutRepository;
use App\Application\Contracts\UseCase;
use App\Application\Inventory\LowStockAlertService;
use App\Application\Support\AuditLogger;
use App\Application\Support\StockBalanceUpdater;
use App\Application\Support\UserNotificationService;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\ExceptionsReturns\Enums\CustomerExchangeStatus;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\QcOutbound\Enums\StockOutStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Domain\SalesOutbound\Enums\SaleOrderStatus;
use App\Models\Product;
use App\Models\CustomerExchange;
use App\Models\MissingItemReport;
use App\Models\SaleOrder;
use App\Models\SaleOrderLine;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StockOut;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PostStockOutUseCase implements UseCase
{
    public function __construct(
        private readonly StockOutRepository $stockOuts,
        private readonly AuditLogger $auditLogger,
        private readonly StockBalanceUpdater $stockBalanceUpdater,
        private readonly LowStockAlertService $lowStockAlertService,
        private readonly UserNotificationService $userNotificationService,
    )
    {
    }

    /**
     * @return array{stock_out: StockOut, replayed: bool}
     */
    public function execute(mixed $payload = null): array
    {
        $data = (array) $payload;

        $existing = $this->stockOuts->findByIdempotencyKey((string) $data['idempotency_key']);
        if ($existing !== null) {
            $this->auditLogger->log(
                userId: (int) ($data['pic_id'] ?? 0),
                moduleName: 'QcOutbound',
                entityName: 'StockOut',
                entityId: (int) $existing->id,
                action: AuditAction::Post,
                newValues: ['replayed' => true, 'idempotency_key' => $data['idempotency_key']],
            );

            return [
                'stock_out' => $existing,
                'replayed' => true,
            ];
        }

        try {
            return DB::transaction(function () use ($data): array {
                $allLines = [
                    ...array_map(
                        static fn (array $line): array => [...$line, 'is_extra' => false],
                        array_values((array) ($data['lines'] ?? [])),
                    ),
                    ...array_map(
                        static fn (array $line): array => [...$line, 'is_extra' => true],
                        array_values((array) ($data['extra_lines'] ?? [])),
                    ),
                ];

                $affectedProductIds = collect($allLines)
                    ->pluck('product_id')
                    ->filter()
                    ->all();
                $beforeLowStockSnapshot = $this->lowStockAlertService->snapshotForProducts($affectedProductIds);
                $usedStockItemIds = [];
                $stockOutLinesBySaleOrderLine = [];
                $saleOrder = null;
                if (!empty($data['sale_order_id'])) {
                    $saleOrder = SaleOrder::query()->lockForUpdate()->find((int) $data['sale_order_id']);
                    if (!$saleOrder || $saleOrder->status !== SaleOrderStatus::Confirmed) {
                        throw ValidationException::withMessages([
                            'sale_order_id' => ['Sale order must exist and be in CONFIRMED status to process a stock out.'],
                        ]);
                    }
                }

                $customerExchange = null;
                $regularLines = array_values(array_filter($allLines, static fn (array $line): bool => ! (bool) ($line['is_extra'] ?? false)));
                $regularSaleOrderLineIds = array_map(
                    static fn (array $line): int => (int) ($line['sale_order_line_id'] ?? 0),
                    $regularLines,
                );

                if (! empty($data['customer_exchange_id'])) {
                    if ($saleOrder === null) {
                        throw ValidationException::withMessages([
                            'customer_exchange_id' => ['Customer exchange stock out requires a sale order.'],
                        ]);
                    }

                    if ((int) $data['customer_id'] !== (int) $saleOrder->customer_id || ($data['extra_lines'] ?? []) !== []) {
                        throw ValidationException::withMessages([
                            'customer_exchange_id' => ['Customer exchange stock out must use its sale order customer and cannot include extra lines.'],
                        ]);
                    }

                    $customerExchange = CustomerExchange::query()
                        ->with('lines')
                        ->lockForUpdate()
                        ->findOrFail((int) $data['customer_exchange_id']);

                    if ($customerExchange->status !== CustomerExchangeStatus::Pending
                        || (int) $customerExchange->sale_order_id !== (int) $saleOrder->id) {
                        throw ValidationException::withMessages([
                            'customer_exchange_id' => ['Customer exchange must be pending and linked to the selected sale order.'],
                        ]);
                    }

                    $expectedLineIds = $customerExchange->lines->pluck('sale_order_line_id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
                    $actualLineIds = collect($regularSaleOrderLineIds)->filter()->sort()->values()->all();
                    if (count($actualLineIds) !== count(array_unique($actualLineIds)) || $actualLineIds !== $expectedLineIds) {
                        throw ValidationException::withMessages([
                            'lines' => ['Customer exchange stock out must dispatch every pending exchange line exactly once.'],
                        ]);
                    }

                    foreach ($regularLines as $line) {
                        $exchangeLine = $customerExchange->lines->firstWhere('sale_order_line_id', (int) $line['sale_order_line_id']);
                        if (! $exchangeLine
                            || (int) $exchangeLine->replacement_product_id !== (int) $line['product_id']
                            || (int) $exchangeLine->qty !== (int) $line['qty']) {
                            throw ValidationException::withMessages([
                                'lines' => ['Customer exchange stock out lines must match the approved exchange products and quantities.'],
                            ]);
                        }
                    }
                } elseif ($regularSaleOrderLineIds !== []) {
                    $hasExchangeLine = SaleOrderLine::query()
                        ->whereIn('id', array_filter($regularSaleOrderLineIds))
                        ->where('line_type', 'EXCHANGE')
                        ->exists();
                    if ($hasExchangeLine) {
                        throw ValidationException::withMessages([
                            'customer_exchange_id' => ['Exchange sale order lines must be dispatched using their customer_exchange_id.'],
                        ]);
                    }
                }

                $stockOutData = [
                    'sale_order_id' => $saleOrder?->id,
                    'customer_exchange_id' => $customerExchange?->id,
                    'quick_stock_out_id' => $data['quick_stock_out_id'] ?? null,
                    'stock_out_number' => $data['stock_out_number'],
                    'idempotency_key' => $data['idempotency_key'],
                    'stock_out_date' => $data['stock_out_date'],
                    'customer_id' => $data['customer_id'],
                    'pic_id' => $data['pic_id'],
                    'pick_list_reference' => $data['pick_list_reference'] ?? null,
                    'status' => StockOutStatus::Posted,
                    'remarks' => $data['remarks'] ?? null,
                ];

                // Backward compatibility: some databases still keep invoice_number as NOT NULL on stock_out.
                if (Schema::hasColumn('stock_out', 'invoice_number')) {
                    $stockOutData['invoice_number'] = (string) (
                        $data['invoice_number']
                        ?? $saleOrder?->invoice_number
                        ?? $data['stock_out_number']
                    );
                }

                $stockOut = $this->stockOuts->create($stockOutData);

            foreach ($allLines as $line) {
                $product = Product::query()->findOrFail((int) $line['product_id']);
                $qty = (int) $line['qty'];
                $saleOrderLineId = $line['sale_order_line_id'] ?? null;
                $quickStockOutLineId = $line['quick_stock_out_line_id'] ?? null;
                $isExtra = (bool) ($line['is_extra'] ?? false);

                if ($saleOrder && !$isExtra && $saleOrderLineId) {
                    $saleOrderLine = SaleOrderLine::query()
                        ->where('sale_order_id', $saleOrder->id)
                        ->where('id', $saleOrderLineId)
                        ->where('product_id', $product->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$saleOrderLine) {
                        throw ValidationException::withMessages([
                            'lines' => ['Sale order line does not match the product or sale order.'],
                        ]);
                    }

                    $remainingQty = max(0, $saleOrderLine->ordered_qty - $saleOrderLine->fulfilled_qty);
                    if ($qty > $remainingQty) {
                        throw ValidationException::withMessages([
                            'lines' => [sprintf('Cannot overship sale order line. Remaining: %d, requested: %d.', $remainingQty, $qty)],
                        ]);
                    }

                    $saleOrderLine->increment('fulfilled_qty', $qty);
                } elseif ($saleOrder && !$isExtra && !$saleOrderLineId) {
                    throw ValidationException::withMessages([
                        'lines' => ['Sale order line ID is required when fulfilling a sale order.'],
                    ]);
                }

                $stockOutLine = $stockOut->lines()->create([
                    'sale_order_line_id' => $saleOrderLineId,
                    'is_extra' => $isExtra,
                    'quick_stock_out_line_id' => $quickStockOutLineId,
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'settled_qty' => 0,
                    'remarks' => $line['remarks'] ?? null,
                ]);

                if ($saleOrderLineId !== null) {
                    $stockOutLinesBySaleOrderLine[(int) $saleOrderLineId] = (int) $stockOutLine->id;
                }

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
                            'lines' => ['Duplicate stock_item_ids are not allowed across lines in one stock out request.'],
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
                        ->withoutUnresolvedMissingItemReport()
                        ->lockForUpdate()
                        ->get();

                    if ($stockItems->count() !== count($stockItemIds)) {
                        throw ValidationException::withMessages([
                            'lines' => ['Some serials are invalid for this line, not currently IN_STOCK, or have not passed QC (must be QC_PASSED to dispatch).'],
                        ]);
                    }

                    $usedStockItemIds = array_values(array_merge($usedStockItemIds, $stockItemIds));
                    $quickStockOutLineItemIds = array_values(array_map('intval', Arr::wrap($line['quick_stock_out_line_item_ids'] ?? [])));
                    if ($quickStockOutLineItemIds !== [] && count($quickStockOutLineItemIds) !== count($stockItemIds)) {
                        throw ValidationException::withMessages([
                            'lines' => ['quick_stock_out_line_item_ids count must match stock_item_ids count when provided.'],
                        ]);
                    }
                    $quickStockOutLineItemMap = $quickStockOutLineItemIds === []
                        ? []
                        : array_combine($stockItemIds, $quickStockOutLineItemIds);

                    foreach ($stockItems as $stockItem) {
                        $lineItem = $stockOutLine->lineItems()->create([
                            'stock_item_id' => $stockItem->id,
                            'quick_stock_out_line_item_id' => $quickStockOutLineItemMap[(int) $stockItem->id] ?? null,
                        ]);

                        /** @var StockItem $stockItem */
                        $stockItem->update([
                            'current_status' => StockItemStatus::Delivered,
                            'is_available' => false,
                            'last_movement_at' => now(),
                        ]);

                        StockMovement::query()->create([
                            'movement_datetime' => now(),
                            'product_id' => $product->id,
                            'stock_item_id' => $stockItem->id,
                            'movement_type' => MovementType::StockOut,
                            'reference_table' => 'stock_out_line_items',
                            'reference_id' => (int) $lineItem->id,
                            'qty_in' => 0,
                            'qty_out' => 1,
                            'from_status' => StockItemStatus::InStock->value,
                            'to_status' => StockItemStatus::Delivered->value,
                            'performed_by' => (int) $data['pic_id'],
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
                    (int) ($movementTotals->qty_in_stock_in ?? 0) - (int) ($movementTotals->qty_in_stock_out ?? 0) - $this->unresolvedNonSerializedShortage((int) $product->id),
                    0,
                );
                if ($availableQty < $qty) {
                    throw ValidationException::withMessages([
                        'lines' => [sprintf('Insufficient stock for product %s. Available: %d, requested: %d.', $product->product_code, $availableQty, $qty)],
                    ]);
                }

                StockMovement::query()->create([
                    'movement_datetime' => now(),
                    'product_id' => $product->id,
                    'stock_item_id' => null,
                    'movement_type' => MovementType::StockOut,
                    'reference_table' => 'stock_out_lines',
                    'reference_id' => (int) $stockOutLine->id,
                    'qty_in' => 0,
                    'qty_out' => $qty,
                    'from_status' => StockItemStatus::InStock->value,
                    'to_status' => StockItemStatus::Delivered->value,
                    'performed_by' => (int) $data['pic_id'],
                    'remarks' => $line['remarks'] ?? null,
                ]);

            }

                if ($saleOrder) {
                    $allLinesFulfilled = true;
                    // Re-query lines to get fresh fulfilled_qty
                    foreach ($saleOrder->lines()->get() as $soLine) {
                        if ($soLine->fulfilled_qty < $soLine->ordered_qty) {
                            $allLinesFulfilled = false;
                            break;
                        }
                    }

                    if ($allLinesFulfilled) {
                        $saleOrder->status = SaleOrderStatus::Fulfilled;
                        $saleOrder->save();
                    }
                }

                if ($customerExchange !== null) {
                    foreach ($customerExchange->lines as $exchangeLine) {
                        $exchangeLine->update([
                            'replacement_stock_out_line_id' => $stockOutLinesBySaleOrderLine[(int) $exchangeLine->sale_order_line_id],
                        ]);
                    }

                    $customerExchange->update([
                        'replacement_stock_out_id' => $stockOut->id,
                        'status' => CustomerExchangeStatus::Dispatched,
                    ]);
                }

                $this->stockBalanceUpdater->recomputeForProducts($affectedProductIds);
                $this->lowStockAlertService->notifyStatusTransitions(
                    $beforeLowStockSnapshot,
                    $affectedProductIds,
                    (int) $data['pic_id'],
                );

                $result = [
                    'stock_out' => $stockOut->fresh(['saleOrder', 'customerExchange', 'lines.saleOrderLine', 'lines.lineItems.stockItem']),
                    'replayed' => false,
                ];

                $this->auditLogger->log(
                    userId: (int) $data['pic_id'],
                    moduleName: 'QcOutbound',
                    entityName: 'StockOut',
                    entityId: (int) $result['stock_out']->id,
                    action: AuditAction::Post,
                    newValues: ['stock_out_number' => $result['stock_out']->stock_out_number, 'status' => $result['stock_out']->status?->value],
                );

                $this->userNotificationService->notifyAllActiveUsers(
                    eventType: 'stock-out.posted',
                    title: 'Stock out posted',
                    message: sprintf('Stock out %s was posted.', $result['stock_out']->stock_out_number),
                    data: [
                        'stock_out_id' => (int) $result['stock_out']->id,
                        'stock_out_number' => $result['stock_out']->stock_out_number,
                        'sale_order_id' => $saleOrder?->id,
                        'sale_order_status' => $saleOrder?->status?->value,
                    ],
                    exceptUserId: (int) $data['pic_id'],
                    level: 'success',
                );

                if ($saleOrder !== null && $saleOrder->status === SaleOrderStatus::Fulfilled) {
                    $this->userNotificationService->notifyAllActiveUsers(
                        eventType: 'sale-order.status-changed',
                        title: 'Sales order fulfilled',
                        message: sprintf('Sales order %s is now FULFILLED.', $saleOrder->so_number),
                        data: [
                            'sale_order_id' => (int) $saleOrder->id,
                            'so_number' => $saleOrder->so_number,
                            'status' => $saleOrder->status->value,
                            'transition' => 'auto-fulfilled',
                            'trigger_stock_out_id' => (int) $result['stock_out']->id,
                        ],
                        exceptUserId: (int) $data['pic_id'],
                        level: 'success',
                    );
                }

                return $result;
            });
        } catch (QueryException $exception) {
            $replayed = $this->stockOuts->findByIdempotencyKey((string) $data['idempotency_key']);
            if ($replayed !== null) {
                return [
                    'stock_out' => $replayed,
                    'replayed' => true,
                ];
            }

            throw $exception;
        }
    }

    private function unresolvedNonSerializedShortage(int $productId): int
    {
        return (int) MissingItemReport::query()
            ->where('product_id', $productId)
            ->whereNull('stock_item_id')
            ->unresolved()
            ->lockForUpdate()
            ->sum('missing_qty');
    }
}
