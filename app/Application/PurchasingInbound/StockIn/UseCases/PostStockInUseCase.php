<?php

namespace App\Application\PurchasingInbound\StockIn\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Application\Support\SerialNumberGenerator;
use App\Application\Support\StockBalanceService;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\SerialSource;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\MasterData\Enums\ProductType;
use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Domain\PurchasingInbound\Enums\StockInStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockIn;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostStockInUseCase implements UseCase
{
    public function __construct(
        private readonly SerialNumberGenerator $serialNumberGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly StockBalanceService $stockBalances,
    )
    {
    }

    public function execute(mixed $payload = null): StockIn
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): StockIn {
            $purchaseOrderId = $data['purchase_order_id'] ?? null;
            $purchaseOrder = null;

            if ($purchaseOrderId !== null) {
                $purchaseOrder = PurchaseOrder::query()->with('lines')->findOrFail((int) $purchaseOrderId);
                if ((int) $purchaseOrder->supplier_id !== (int) $data['supplier_id']) {
                    throw ValidationException::withMessages([
                        'purchase_order_id' => ['Purchase order supplier does not match selected supplier.'],
                    ]);
                }

                if (in_array($purchaseOrder->status, [PurchaseOrderStatus::Cancelled, PurchaseOrderStatus::Completed], true)) {
                    throw ValidationException::withMessages([
                        'purchase_order_id' => ['Stock in cannot be posted to a cancelled or completed purchase order.'],
                    ]);
                }

                $this->validatePurchaseOrderReceiving($purchaseOrder, $data['lines']);
            }

            $stockIn = StockIn::query()->create([
                'stock_in_number' => $data['stock_in_number'],
                'stock_in_date' => $data['stock_in_date'],
                'delivery_order_number' => $data['delivery_order_number'] ?? null,
                'purchase_order_id' => $purchaseOrderId,
                'supplier_id' => $data['supplier_id'],
                'stock_in_pic_id' => $data['stock_in_pic_id'],
                'qc_person_id' => $data['qc_person_id'] ?? null,
                'status' => StockInStatus::Posted,
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $product = Product::query()->findOrFail((int) $line['product_id']);
                $receivedQty = (int) $line['received_qty'];

                $stockInLine = $stockIn->lines()->create([
                    'product_id' => $product->id,
                    'received_qty' => $receivedQty,
                    'condition_at_receiving' => $line['condition_at_receiving'] ?? null,
                    'remarks' => $line['remarks'] ?? null,
                ]);

                if ($purchaseOrder !== null) {
                    $this->applyReceiptToPurchaseOrderLine($purchaseOrder, $product->id, $receivedQty);
                }

                $serials = array_values(array_map('strval', Arr::wrap($line['serial_numbers'] ?? [])));

                if ($product->product_type === ProductType::Device) {
                    if (count($serials) !== $receivedQty) {
                        throw ValidationException::withMessages([
                            'lines' => ['DEVICE items require serial_numbers count to match received_qty.'],
                        ]);
                    }

                    foreach ($serials as $serial) {
                        $stockItem = StockItem::query()->create([
                            'product_id' => $product->id,
                            'stock_in_line_id' => $stockInLine->id,
                            'serial_number' => $serial,
                            'serial_source' => SerialSource::Factory,
                            'current_status' => StockItemStatus::Received,
                            'is_available' => true,
                            'last_movement_at' => now(),
                        ]);

                        StockMovement::query()->create([
                            'movement_datetime' => now(),
                            'product_id' => $product->id,
                            'stock_item_id' => $stockItem->id,
                            'movement_type' => MovementType::StockIn,
                            'reference_table' => 'stock_in_lines',
                            'reference_id' => $stockInLine->id,
                            'qty_in' => 1,
                            'qty_out' => 0,
                            'to_status' => StockItemStatus::Received->value,
                            'performed_by' => (int) $data['stock_in_pic_id'],
                            'remarks' => $line['remarks'] ?? null,
                        ]);

                        $this->stockBalances->incrementStatus($product->id, StockItemStatus::Received, 1);
                    }

                    continue;
                }

                if ($product->product_type === ProductType::Accessory) {
                    for ($i = 0; $i < $receivedQty; $i++) {
                        $stockItem = StockItem::query()->create([
                            'product_id' => $product->id,
                            'stock_in_line_id' => $stockInLine->id,
                            'serial_number' => $this->serialNumberGenerator->generate($product->product_code),
                            'serial_source' => SerialSource::Generated,
                            'current_status' => StockItemStatus::Received,
                            'is_available' => true,
                            'last_movement_at' => now(),
                        ]);

                        StockMovement::query()->create([
                            'movement_datetime' => now(),
                            'product_id' => $product->id,
                            'stock_item_id' => $stockItem->id,
                            'movement_type' => MovementType::StockIn,
                            'reference_table' => 'stock_in_lines',
                            'reference_id' => $stockInLine->id,
                            'qty_in' => 1,
                            'qty_out' => 0,
                            'to_status' => StockItemStatus::Received->value,
                            'performed_by' => (int) $data['stock_in_pic_id'],
                            'remarks' => $line['remarks'] ?? null,
                        ]);

                        $this->stockBalances->incrementStatus($product->id, StockItemStatus::Received, 1);
                    }

                    continue;
                }

                StockMovement::query()->create([
                    'movement_datetime' => now(),
                    'product_id' => $product->id,
                    'stock_item_id' => null,
                    'movement_type' => MovementType::StockIn,
                    'reference_table' => 'stock_in_lines',
                    'reference_id' => $stockInLine->id,
                    'qty_in' => $receivedQty,
                    'qty_out' => 0,
                    'performed_by' => (int) $data['stock_in_pic_id'],
                    'remarks' => $line['remarks'] ?? null,
                ]);

                $this->stockBalances->incrementStatus($product->id, StockItemStatus::Received, $receivedQty);

            }

            if ($purchaseOrder !== null && $this->isPurchaseOrderFulfilled($purchaseOrder)) {
                $purchaseOrder->status = PurchaseOrderStatus::Completed;
                $purchaseOrder->save();
            }

            $result = $stockIn->fresh('lines.stockItems');

            $this->auditLogger->log(
                userId: (int) $data['stock_in_pic_id'],
                moduleName: 'PurchasingInbound',
                entityName: 'StockIn',
                entityId: (int) $result->id,
                action: AuditAction::Post,
                newValues: ['stock_in_number' => $result->stock_in_number, 'status' => $result->status?->value],
            );

            return $result;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $stockInLines
     */
    private function validatePurchaseOrderReceiving(PurchaseOrder $purchaseOrder, array $stockInLines): void
    {
        $incomingByProduct = [];

        foreach ($stockInLines as $line) {
            $productId = (int) $line['product_id'];
            $incomingByProduct[$productId] = ($incomingByProduct[$productId] ?? 0) + (int) $line['received_qty'];
        }

        foreach ($incomingByProduct as $productId => $incomingQty) {
            $matchingLines = $purchaseOrder->lines->where('product_id', $productId);

            if ($matchingLines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => [sprintf('Product %d is not present in the selected purchase order.', $productId)],
                ]);
            }

            $orderedQty = (int) $matchingLines->sum('ordered_qty');
            $receivedQty = (int) $matchingLines->sum('received_qty');

            if ($receivedQty + $incomingQty > $orderedQty) {
                throw ValidationException::withMessages([
                    'lines' => [sprintf('Received quantity for product %d exceeds ordered quantity.', $productId)],
                ]);
            }
        }
    }

    private function applyReceiptToPurchaseOrderLine(PurchaseOrder $purchaseOrder, int $productId, int $incomingQty): void
    {
        $remaining = $incomingQty;

        /** @var PurchaseOrderLine $line */
        foreach ($purchaseOrder->lines->where('product_id', $productId)->sortBy('id') as $line) {
            $lineRemaining = (int) $line->ordered_qty - (int) $line->received_qty;
            if ($lineRemaining <= 0) {
                continue;
            }

            $toApply = min($remaining, $lineRemaining);
            $line->received_qty = (int) $line->received_qty + $toApply;
            $line->save();

            $remaining -= $toApply;
            if ($remaining === 0) {
                break;
            }
        }
    }

    private function isPurchaseOrderFulfilled(PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->lines->every(function (PurchaseOrderLine $line): bool {
            return (int) $line->received_qty >= (int) $line->ordered_qty;
        });
    }
}
