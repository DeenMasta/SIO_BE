<?php

namespace App\Application\QcOutbound\StockOut\UseCases;

use App\Application\Contracts\Repositories\StockOutRepository;
use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Application\Support\StockBalanceUpdater;
use App\Application\Support\UserNotificationService;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\QcOutbound\Enums\StockOutStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Domain\SalesOutbound\Enums\SaleOrderStatus;
use App\Models\Product;
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
                $affectedProductIds = [];
                $usedStockItemIds = [];
                $saleOrder = null;
                if (!empty($data['sale_order_id'])) {
                    $saleOrder = SaleOrder::query()->lockForUpdate()->find((int) $data['sale_order_id']);
                    if (!$saleOrder || $saleOrder->status !== SaleOrderStatus::Confirmed) {
                        throw ValidationException::withMessages([
                            'sale_order_id' => ['Sale order must exist and be in CONFIRMED status to process a stock out.'],
                        ]);
                    }
                }

                $stockOutData = [
                    'sale_order_id' => $saleOrder?->id,
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

            foreach ($data['lines'] as $line) {
                $product = Product::query()->findOrFail((int) $line['product_id']);
                $affectedProductIds[] = (int) $product->id;
                $qty = (int) $line['qty'];
                $saleOrderLineId = $line['sale_order_line_id'] ?? null;

                if ($saleOrder && $saleOrderLineId) {
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
                } elseif ($saleOrder && !$saleOrderLineId) {
                    throw ValidationException::withMessages([
                        'lines' => ['Sale order line ID is required when fulfilling a sale order.'],
                    ]);
                }

                $stockOutLine = $stockOut->lines()->create([
                    'sale_order_line_id' => $saleOrderLineId,
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'remarks' => $line['remarks'] ?? null,
                ]);

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
                        ->lockForUpdate()
                        ->get();

                    if ($stockItems->count() !== count($stockItemIds)) {
                        throw ValidationException::withMessages([
                            'lines' => ['Some serials are invalid for this line, not currently IN_STOCK, or have not passed QC (must be QC_PASSED to dispatch).'],
                        ]);
                    }

                    $usedStockItemIds = array_values(array_merge($usedStockItemIds, $stockItemIds));

                    foreach ($stockItems as $stockItem) {
                        $lineItem = $stockOutLine->lineItems()->create([
                            'stock_item_id' => $stockItem->id,
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
                    (int) ($movementTotals->qty_in_stock_in ?? 0) - (int) ($movementTotals->qty_in_stock_out ?? 0),
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

                $this->stockBalanceUpdater->recomputeForProducts($affectedProductIds);

                $result = [
                    'stock_out' => $stockOut->fresh('lines.lineItems', 'saleOrder'),
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
}
