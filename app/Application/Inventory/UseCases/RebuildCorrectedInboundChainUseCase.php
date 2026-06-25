<?php

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Support\DocumentNumberGenerator;
use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Domain\PurchasingInbound\Enums\StockInStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\QcCheck;
use App\Models\QcDocument;
use App\Models\StockIn;
use App\Models\StockInLine;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RebuildCorrectedInboundChainUseCase implements UseCase
{
    public function __construct(
        private readonly DocumentNumberGenerator $documentNumberGenerator,
    ) {
    }

    /**
     * @param  array{
     *     purchase_order_line_id:int,
     *     stock_in_line_id:int,
     *     supplier_id:int,
     *     po_date?:string|null,
     *     expected_delivery_date?:string|null,
     *     stock_in_date?:string|null,
     *     qc_date?:string|null,
     *     po_number?:string|null,
     *     stock_in_number?:string|null,
     *     qc_number?:string|null
     * }  $payload
     * @return array<string, mixed>
     */
    public function execute(mixed $payload = null): array
    {
        $data = (array) $payload;

        $purchaseOrderLineId = (int) ($data['purchase_order_line_id'] ?? 0);
        $stockInLineId = (int) ($data['stock_in_line_id'] ?? 0);
        $supplierId = (int) ($data['supplier_id'] ?? 0);

        if ($purchaseOrderLineId <= 0 || $stockInLineId <= 0 || $supplierId <= 0) {
            throw ValidationException::withMessages([
                'input' => ['purchase_order_line_id, stock_in_line_id, and supplier_id are required.'],
            ]);
        }

        /** @var Supplier $supplier */
        $supplier = Supplier::query()->findOrFail($supplierId);

        return DB::transaction(function () use ($data, $purchaseOrderLineId, $stockInLineId, $supplier): array {
            /** @var PurchaseOrderLine $oldPurchaseOrderLine */
            $oldPurchaseOrderLine = PurchaseOrderLine::query()
                ->with(['purchaseOrder', 'product'])
                ->lockForUpdate()
                ->findOrFail($purchaseOrderLineId);

            /** @var StockInLine $oldStockInLine */
            $oldStockInLine = StockInLine::query()
                ->with(['stockIn', 'product'])
                ->lockForUpdate()
                ->findOrFail($stockInLineId);

            if ((int) $oldStockInLine->purchase_order_line_id !== (int) $oldPurchaseOrderLine->id) {
                throw ValidationException::withMessages([
                    'stock_in_line_id' => ['The stock in line is not linked to the selected purchase order line.'],
                ]);
            }

            /** @var Product|null $product */
            $product = $oldPurchaseOrderLine->product;
            if ($product === null) {
                throw ValidationException::withMessages([
                    'purchase_order_line_id' => ['The selected purchase order line does not have a product.'],
                ]);
            }

            if ((int) $oldPurchaseOrderLine->product_id !== (int) $oldStockInLine->product_id) {
                throw ValidationException::withMessages([
                    'purchase_order_line_id' => ['The purchase order line and stock in line point to different products.'],
                ]);
            }

            if ($product->supplier_id !== null && (int) $product->supplier_id !== (int) $supplier->id) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['The selected supplier does not match the corrected product supplier.'],
                ]);
            }

            /** @var PurchaseOrder|null $oldPurchaseOrder */
            $oldPurchaseOrder = $oldPurchaseOrderLine->purchaseOrder;
            /** @var StockIn|null $oldStockIn */
            $oldStockIn = $oldStockInLine->stockIn;

            if ($oldPurchaseOrder === null || $oldStockIn === null) {
                throw ValidationException::withMessages([
                    'input' => ['The selected chain is missing its purchase order or stock in header.'],
                ]);
            }

            $stockItems = StockItem::query()
                ->where('stock_in_line_id', $oldStockInLine->id)
                ->lockForUpdate()
                ->get();

            if ($stockItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'stock_in_line_id' => ['No stock items were found for the selected stock in line.'],
                ]);
            }

            $stockItemIds = $stockItems->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            $oldChecks = QcCheck::query()
                ->whereIn('stock_item_id', $stockItemIds)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            $oldCheckIds = $oldChecks->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $oldQcDocumentId = $oldChecks->pluck('qc_document_id')->filter()->unique()->first();
            $oldQcDocument = $oldQcDocumentId !== null
                ? QcDocument::query()->lockForUpdate()->find((int) $oldQcDocumentId)
                : null;

            $newPurchaseOrder = $this->createPurchaseOrder(
                oldPurchaseOrder: $oldPurchaseOrder,
                oldPurchaseOrderLine: $oldPurchaseOrderLine,
                supplier: $supplier,
                data: $data,
            );

            $newStockIn = $this->createStockIn(
                oldStockIn: $oldStockIn,
                supplier: $supplier,
                purchaseOrderId: (int) $newPurchaseOrder->id,
                data: $data,
            );

            $newStockInLine = $newStockIn->lines()->create([
                'purchase_order_line_id' => (int) $newPurchaseOrder->lines()->value('id'),
                'product_id' => $product->id,
                'received_qty' => (int) $oldStockInLine->received_qty,
                'remarks' => $oldStockInLine->remarks,
            ]);

            StockItem::query()
                ->whereIn('id', $stockItemIds)
                ->update(['stock_in_line_id' => $newStockInLine->id]);

            $stockInMovements = StockMovement::query()
                ->where('reference_table', 'stock_in_lines')
                ->where('reference_id', $oldStockInLine->id)
                ->lockForUpdate()
                ->get();

            foreach ($stockInMovements as $movement) {
                $movement->reference_id = (int) $newStockInLine->id;

                if (! empty($data['stock_in_date'])) {
                    $movement->movement_datetime = $this->replaceDatePreservingTime(
                        $movement->movement_datetime,
                        (string) $data['stock_in_date'],
                    );
                }

                $movement->save();
            }

            $newQcDocument = null;
            $newCheckIds = [];

            if ($oldChecks->isNotEmpty()) {
                $newQcDocument = $this->createQcDocument(
                    oldStockIn: $oldStockIn,
                    newStockInId: (int) $newStockIn->id,
                    oldQcDocument: $oldQcDocument,
                    data: $data,
                );

                $checkIdMap = [];

                foreach ($oldChecks as $oldCheck) {
                    $newCheck = QcCheck::query()->create([
                        'qc_document_id' => (int) $newQcDocument->id,
                        'stock_item_id' => (int) $oldCheck->stock_item_id,
                        'result' => $oldCheck->result?->value ?? (string) $oldCheck->result,
                        'checked_at' => ! empty($data['qc_date'])
                            ? $this->replaceDatePreservingTime($oldCheck->checked_at, (string) $data['qc_date'])
                            : $oldCheck->checked_at,
                        'checked_conditions' => (array) $oldCheck->checked_conditions,
                        'checked_accessories' => (array) $oldCheck->checked_accessories,
                        'remarks' => $oldCheck->remarks,
                    ]);

                    $checkIdMap[(int) $oldCheck->id] = (int) $newCheck->id;
                    $newCheckIds[] = (int) $newCheck->id;
                }

                $qcMovements = StockMovement::query()
                    ->where('reference_table', 'qc_items')
                    ->whereIn('reference_id', $oldCheckIds)
                    ->lockForUpdate()
                    ->get();

                foreach ($qcMovements as $movement) {
                    $newReferenceId = $checkIdMap[(int) $movement->reference_id] ?? null;
                    if ($newReferenceId === null) {
                        continue;
                    }

                    $movement->reference_id = $newReferenceId;

                    if (! empty($data['qc_date'])) {
                        $movement->movement_datetime = $this->replaceDatePreservingTime(
                            $movement->movement_datetime,
                            (string) $data['qc_date'],
                        );
                    }

                    $movement->save();
                }

                QcCheck::query()->whereIn('id', $oldCheckIds)->delete();

                if ($oldQcDocument !== null && ! $oldQcDocument->checks()->exists()) {
                    $oldQcDocument->delete();
                }
            }

            $oldStockInLine->delete();
            $oldPurchaseOrderLine->delete();

            $this->cleanupOldHeaders($oldPurchaseOrder, $oldStockIn);

            return [
                'new_purchase_order_id' => (int) $newPurchaseOrder->id,
                'new_po_number' => $newPurchaseOrder->po_number,
                'new_stock_in_id' => (int) $newStockIn->id,
                'new_stock_in_number' => $newStockIn->stock_in_number,
                'new_stock_in_line_id' => (int) $newStockInLine->id,
                'new_qc_document_id' => $newQcDocument?->id,
                'new_qc_number' => $newQcDocument?->document_number,
                'supplier_id' => (int) $supplier->id,
                'supplier_name' => $supplier->supplier_name,
                'product_id' => (int) $product->id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'moved_stock_item_ids' => $stockItemIds,
                'moved_qc_check_ids' => $newCheckIds,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createPurchaseOrder(
        PurchaseOrder $oldPurchaseOrder,
        PurchaseOrderLine $oldPurchaseOrderLine,
        Supplier $supplier,
        array $data,
    ): PurchaseOrder {
        $poDate = ! empty($data['po_date'])
            ? Carbon::parse((string) $data['po_date'])->toDateString()
            : ($oldPurchaseOrder->po_date?->toDateString() ?? now()->toDateString());

        $expectedDeliveryDate = ! empty($data['expected_delivery_date'])
            ? Carbon::parse((string) $data['expected_delivery_date'])->toDateString()
            : $oldPurchaseOrder->expected_delivery_date?->toDateString();

        $poNumber = trim((string) ($data['po_number'] ?? '')) !== ''
            ? trim((string) $data['po_number'])
            : $this->documentNumberGenerator->generatePurchaseOrderNumber();

        $purchaseOrder = PurchaseOrder::query()->create([
            'po_number' => $poNumber,
            'po_date' => $poDate,
            'supplier_id' => (int) $supplier->id,
            'expected_delivery_date' => $expectedDeliveryDate,
            'status' => (int) $oldPurchaseOrderLine->received_qty >= (int) $oldPurchaseOrderLine->ordered_qty
                ? PurchaseOrderStatus::Completed
                : PurchaseOrderStatus::Issued,
            'created_by' => (int) $oldPurchaseOrder->created_by,
            'remarks' => $oldPurchaseOrder->remarks,
        ]);

        $purchaseOrder->lines()->create([
            'product_id' => (int) $oldPurchaseOrderLine->product_id,
            'ordered_qty' => (int) $oldPurchaseOrderLine->ordered_qty,
            'received_qty' => (int) $oldPurchaseOrderLine->received_qty,
            'unit_price' => $oldPurchaseOrderLine->unit_price,
            'subtotal' => $oldPurchaseOrderLine->subtotal,
            'remarks' => $oldPurchaseOrderLine->remarks,
        ]);

        return $purchaseOrder->fresh('lines');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createStockIn(
        StockIn $oldStockIn,
        Supplier $supplier,
        int $purchaseOrderId,
        array $data,
    ): StockIn {
        $stockInDate = ! empty($data['stock_in_date'])
            ? Carbon::parse((string) $data['stock_in_date'])->toDateString()
            : ($oldStockIn->stock_in_date?->toDateString() ?? now()->toDateString());

        $stockInNumber = trim((string) ($data['stock_in_number'] ?? '')) !== ''
            ? trim((string) $data['stock_in_number'])
            : $this->documentNumberGenerator->generateStockInNumber();

        return StockIn::query()->create([
            'stock_in_number' => $stockInNumber,
            'stock_in_date' => $stockInDate,
            'purchase_order_id' => $purchaseOrderId,
            'supplier_id' => (int) $supplier->id,
            'stock_in_pic_id' => (int) $oldStockIn->stock_in_pic_id,
            'status' => $oldStockIn->status ?? StockInStatus::Posted,
            'remarks' => $oldStockIn->remarks,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createQcDocument(
        StockIn $oldStockIn,
        int $newStockInId,
        ?QcDocument $oldQcDocument,
        array $data,
    ): QcDocument {
        $qcDate = ! empty($data['qc_date'])
            ? Carbon::parse((string) $data['qc_date'])->toDateString()
            : ($oldQcDocument?->date?->toDateString() ?? $oldStockIn->stock_in_date?->toDateString() ?? now()->toDateString());

        $qcNumber = trim((string) ($data['qc_number'] ?? '')) !== ''
            ? trim((string) $data['qc_number'])
            : $this->documentNumberGenerator->generateQcDocumentNumber();

        return QcDocument::query()->create([
            'document_number' => $qcNumber,
            'date' => $qcDate,
            'pic_id' => (int) ($oldQcDocument?->pic_id ?? $oldStockIn->stock_in_pic_id),
            'stock_in_id' => $newStockInId,
            'status' => (string) ($oldQcDocument?->status ?? 'POSTED'),
            'remarks' => $oldQcDocument?->remarks,
        ]);
    }

    private function cleanupOldHeaders(PurchaseOrder $oldPurchaseOrder, StockIn $oldStockIn): void
    {
        $oldStockIn->refresh();
        if (! $oldStockIn->lines()->exists() && ! QcDocument::query()->where('stock_in_id', $oldStockIn->id)->exists()) {
            $oldStockIn->delete();
        }

        $oldPurchaseOrder->refresh();
        if (! $oldPurchaseOrder->lines()->exists() && ! StockIn::query()->where('purchase_order_id', $oldPurchaseOrder->id)->exists()) {
            $oldPurchaseOrder->delete();
        }
    }

    private function replaceDatePreservingTime(mixed $currentDateTime, string $targetDate): Carbon
    {
        $current = $currentDateTime instanceof Carbon
            ? $currentDateTime->copy()
            : Carbon::parse((string) $currentDateTime);

        $date = Carbon::parse($targetDate);

        return $current->setDate($date->year, $date->month, $date->day);
    }
}
