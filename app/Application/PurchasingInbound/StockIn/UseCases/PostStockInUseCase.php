<?php

namespace App\Application\PurchasingInbound\StockIn\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Application\Support\SerialNumberGenerator;
use App\Application\Support\StockBalanceUpdater;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\SerialSource;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostStockInUseCase implements UseCase
{
    public function __construct(
        private readonly SerialNumberGenerator $serialNumberGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly StockBalanceUpdater $stockBalanceUpdater,
    )
    {
    }

    public function execute(mixed $payload = null): StockIn
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): StockIn {
            $affectedProductIds = [];
            $purchaseOrderId = $data['purchase_order_id'] ?? null;
            $purchaseOrder = null;

            if ($purchaseOrderId !== null) {
                $purchaseOrder = PurchaseOrder::query()->with('lines.product')->findOrFail((int) $purchaseOrderId);
                if ((int) $purchaseOrder->supplier_id !== (int) $data['supplier_id']) {
                    throw ValidationException::withMessages([
                        'purchase_order_id' => ['Purchase order supplier does not match selected supplier.'],
                    ]);
                }

                if ($purchaseOrder->status !== PurchaseOrderStatus::Issued) {
                    throw ValidationException::withMessages([
                        'purchase_order_id' => ['Stock in can only be received from an issued purchase order.'],
                    ]);
                }

                $this->validatePurchaseOrderReceiving($purchaseOrder, $data['lines']);
            }

            $this->validateSerialNumbers($data['lines']);

            $stockIn = StockIn::query()->create([
                'stock_in_number' => $data['stock_in_number'],
                'stock_in_date' => $data['stock_in_date'],
                'purchase_order_id' => $purchaseOrderId,
                'supplier_id' => $data['supplier_id'],
                'stock_in_pic_id' => $data['stock_in_pic_id'],
                'status' => StockInStatus::Received,
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $purchaseOrderLine = $this->resolvePurchaseOrderLine($purchaseOrder, $line);
                $product = $purchaseOrderLine?->product ?? Product::query()->findOrFail((int) $line['product_id']);
                $affectedProductIds[] = (int) $product->id;
                $receivedQty = (int) $line['received_qty'];
                $allowGeneratedSerials = (bool) ($line['allow_generated_serials'] ?? false);
                $unitReceipts = $this->buildUnitReceipts($line, $receivedQty);

                $stockInLine = $stockIn->lines()->create([
                    'purchase_order_line_id' => $purchaseOrderLine?->id,
                    'product_id' => $product->id,
                    'received_qty' => $receivedQty,
                    'remarks' => $line['remarks'] ?? null,
                ]);

                if ($purchaseOrderLine !== null) {
                    $this->applyReceiptToPurchaseOrderLine($purchaseOrderLine, $receivedQty);
                }

                $serials = array_values(array_map('strval', Arr::wrap($line['serial_numbers'] ?? [])));

                if ($product->product_type === ProductType::Device) {
                    if (count($unitReceipts) !== $receivedQty) {
                        throw ValidationException::withMessages([
                            'lines' => ['DEVICE items require one unit_receipt per received unit.'],
                        ]);
                    }

                    foreach ($unitReceipts as $unitReceipt) {
                        $incomingSerial = trim((string) ($unitReceipt['serial_number'] ?? ''));
                        $itemRemarks = $unitReceipt['remarks'] ?? ($line['remarks'] ?? null);

                        if ($incomingSerial === '' && ! $allowGeneratedSerials) {
                            throw ValidationException::withMessages([
                                'lines' => ['DEVICE items require serial_number for every unit unless allow_generated_serials is enabled.'],
                            ]);
                        }

                        $serialValue = $incomingSerial !== ''
                            ? $incomingSerial
                            : $this->serialNumberGenerator->generate($product->product_code);

                        $serialSource = $incomingSerial !== '' ? SerialSource::Factory : SerialSource::Generated;

                        $stockItem = StockItem::query()->create([
                            'product_id'             => $product->id,
                            'stock_in_line_id'       => $stockInLine->id,
                            'serial_number'          => $serialValue,
                            'factory_serial_number'  => $incomingSerial !== '' ? $incomingSerial : null,
                            'serial_source'          => $serialSource,
                            'current_status'         => StockItemStatus::InStock,
                            'qc_status'              => StockItemQcStatus::Pending,
                            'is_available'           => true,
                            'last_movement_at'       => now(),
                            'remarks'                => $itemRemarks,
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
                            'to_status' => StockItemStatus::InStock->value,
                            'performed_by' => (int) $data['stock_in_pic_id'],
                            'remarks' => $itemRemarks,
                        ]);

                    }

                    continue;
                }

                if ($product->product_type === ProductType::Accessory) {
                    for ($i = 0; $i < $receivedQty; $i++) {
                        $unitReceipt = $unitReceipts[$i] ?? [];
                        $incomingSerial = trim((string) ($unitReceipt['serial_number'] ?? ''));
                        $itemRemarks = $unitReceipt['remarks'] ?? ($line['remarks'] ?? null);

                        $serialValue = $incomingSerial !== ''
                            ? $incomingSerial
                            : $this->serialNumberGenerator->generate($product->product_code);

                        $serialSource = $incomingSerial !== '' ? SerialSource::Factory : SerialSource::Generated;

                        $stockItem = StockItem::query()->create([
                            'product_id'             => $product->id,
                            'stock_in_line_id'       => $stockInLine->id,
                            'serial_number'          => $serialValue,
                            'factory_serial_number'  => $incomingSerial !== '' ? $incomingSerial : null,
                            'serial_source'          => $serialSource,
                            'current_status'         => StockItemStatus::InStock,
                            'qc_status'              => StockItemQcStatus::Pending,
                            'is_available'           => true,
                            'last_movement_at'       => now(),
                            'remarks'                => $itemRemarks,
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
                            'to_status' => StockItemStatus::InStock->value,
                            'performed_by' => (int) $data['stock_in_pic_id'],
                            'remarks' => $itemRemarks,
                        ]);

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
                    'to_status' => StockItemStatus::InStock->value,
                    'performed_by' => (int) $data['stock_in_pic_id'],
                    'remarks' => $line['remarks'] ?? null,
                ]);

            }

            if ($purchaseOrder !== null) {
                $purchaseOrder->refresh()->load('lines.product');
            }

            if ($purchaseOrder !== null && $this->isPurchaseOrderFulfilled($purchaseOrder)) {
                $purchaseOrder->status = PurchaseOrderStatus::Completed;
                $purchaseOrder->save();
            }

            $this->stockBalanceUpdater->recomputeForProducts($affectedProductIds);

            $result = $stockIn->fresh('lines.product', 'lines.stockItems');

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
        foreach ($stockInLines as $index => $line) {
            $purchaseOrderLineId = (int) ($line['purchase_order_line_id'] ?? 0);
            $purchaseOrderLine = $purchaseOrder->lines->firstWhere('id', $purchaseOrderLineId);

            if (! $purchaseOrderLine instanceof PurchaseOrderLine) {
                throw ValidationException::withMessages([
                    "lines.$index.purchase_order_line_id" => [
                        sprintf('Purchase order line %d does not belong to the selected purchase order.', $purchaseOrderLineId),
                    ],
                ]);
            }

            $productId = $line['product_id'] ?? null;
            if ($productId !== null && (int) $productId !== (int) $purchaseOrderLine->product_id) {
                throw ValidationException::withMessages([
                    "lines.$index.product_id" => [
                        sprintf(
                            'Product %d does not match purchase order line %d.',
                            (int) $productId,
                            $purchaseOrderLineId,
                        ),
                    ],
                ]);
            }

            $orderedQty  = (int) $purchaseOrderLine->ordered_qty;
            $receivedSoFar = (int) $purchaseOrderLine->received_qty;
            $remainingQty = max($orderedQty - $receivedSoFar, 0);

            if ((int) $line['received_qty'] > $remainingQty) {
                throw ValidationException::withMessages([
                    "lines.$index.received_qty" => [
                        sprintf(
                            'Cannot receive %d unit(s) for PO line %d — ordered: %d, already received: %d, remaining: %d.',
                            (int) $line['received_qty'],
                            $purchaseOrderLineId,
                            $orderedQty,
                            $receivedSoFar,
                            $remainingQty,
                        ),
                    ],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function resolvePurchaseOrderLine(?PurchaseOrder $purchaseOrder, array $line): ?PurchaseOrderLine
    {
        if ($purchaseOrder === null) {
            return null;
        }

        $purchaseOrderLine = $purchaseOrder->lines->firstWhere('id', (int) $line['purchase_order_line_id']);

        if (! $purchaseOrderLine instanceof PurchaseOrderLine) {
            throw ValidationException::withMessages([
                'lines' => ['Selected purchase order line is invalid.'],
            ]);
        }

        return $purchaseOrderLine;
    }

    private function applyReceiptToPurchaseOrderLine(PurchaseOrderLine $purchaseOrderLine, int $incomingQty): void
    {
        $purchaseOrderLine->received_qty = (int) $purchaseOrderLine->received_qty + $incomingQty;
        $purchaseOrderLine->save();
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<int, array{serial_number:string, remarks:?string}>
     */
    private function buildUnitReceipts(array $line, int $receivedQty): array
    {
        $unitReceiptsInput = Arr::wrap($line['unit_receipts'] ?? []);

        if ($unitReceiptsInput !== []) {
            return array_map(static function (mixed $unit): array {
                $entry = is_array($unit) ? $unit : [];

                return [
                    'serial_number' => trim((string) ($entry['serial_number'] ?? '')),
                    'remarks' => array_key_exists('remarks', $entry)
                        ? (string) ($entry['remarks'] ?? '')
                        : null,
                ];
            }, $unitReceiptsInput);
        }

        $serials = array_values(array_map('strval', Arr::wrap($line['serial_numbers'] ?? [])));

        if ($serials !== []) {
            return array_map(static fn (string $serial): array => [
                'serial_number' => trim($serial),
                'remarks'       => null,
            ], $serials);
        }

        return array_fill(0, $receivedQty, [
            'serial_number' => '',
            'remarks'       => null,
        ]);
    }

    private function isPurchaseOrderFulfilled(PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->lines->every(function (PurchaseOrderLine $line): bool {
            return (int) $line->received_qty >= (int) $line->ordered_qty;
        });
    }

    /**
     * Validate that no DEVICE serial numbers are duplicated — either within
     * the same request payload or against existing stock_items in the DB.
     *
     * @param  array<int, array<string, mixed>>  $stockInLines
     */
    private function validateSerialNumbers(array $stockInLines): void
    {
        /** @var array<string> $allSerials  All serials across every line in this request */
        $allSerials = [];

        foreach ($stockInLines as $index => $line) {
            $serials = array_values(array_map('strval', Arr::wrap($line['serial_numbers'] ?? [])));
            $unitSerials = array_values(array_filter(array_map(
                static fn (mixed $unit): string => trim((string) (is_array($unit) ? ($unit['serial_number'] ?? '') : '')),
                Arr::wrap($line['unit_receipts'] ?? []),
            )));

            $serials = array_values(array_filter(array_merge($serials, $unitSerials), static fn (string $value): bool => trim($value) !== ''));

            if (empty($serials)) {
                continue;
            }

            // 1. Detect duplicates within this request payload.
            $duplicatesInPayload = array_values(
                array_unique(
                    array_intersect($serials, $allSerials)
                )
            );

            if (! empty($duplicatesInPayload)) {
                throw ValidationException::withMessages([
                    "lines.$index.serial_numbers" => [
                        sprintf(
                            'Duplicate serial number(s) found within the same request: %s.',
                            implode(', ', $duplicatesInPayload)
                        ),
                    ],
                ]);
            }

            array_push($allSerials, ...$serials);
        }

        if (empty($allSerials)) {
            return;
        }

        // 2. Detect serials that already exist in the database.
        /** @var Collection<int, string> $existingSerials */
        $existingSerials = StockItem::query()
            ->whereIn('serial_number', $allSerials)
            ->orWhereIn('factory_serial_number', $allSerials)
            ->get(['serial_number', 'factory_serial_number'])
            ->flatMap(static function (StockItem $item): array {
                return array_values(array_filter([
                    (string) ($item->serial_number ?? ''),
                    (string) ($item->factory_serial_number ?? ''),
                ]));
            })
            ->unique()
            ->values();

        if ($existingSerials->isNotEmpty()) {
            throw ValidationException::withMessages([
                'lines' => [
                    sprintf(
                        'The following serial number(s) are already registered in the system: %s.',
                        $existingSerials->implode(', ')
                    ),
                ],
            ]);
        }
    }
}
