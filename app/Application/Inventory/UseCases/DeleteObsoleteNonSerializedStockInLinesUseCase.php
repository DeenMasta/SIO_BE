<?php

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Support\StockBalanceUpdater;
use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\QcDocument;
use App\Models\ReturnToSupplierLine;
use App\Models\StockIn;
use App\Models\StockInLine;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteObsoleteNonSerializedStockInLinesUseCase implements UseCase
{
    public function __construct(
        private readonly StockBalanceUpdater $stockBalanceUpdater,
    ) {
    }

    /**
     * @param  array{
     *     stock_in_id:int,
     *     stock_in_line_ids:array<int, int|string>
     * }  $payload
     * @return array{
     *     stock_in_id:int,
     *     deleted_line_ids:array<int, int>,
     *     deleted_movement_ids:array<int, int>,
     *     affected_product_ids:array<int, int>,
     *     deleted_empty_stock_in:bool
     * }
     */
    public function execute(mixed $payload = null): array
    {
        $data = is_array($payload) ? $payload : [];
        $stockInId = (int) ($data['stock_in_id'] ?? 0);
        $stockInLineIds = collect((array) ($data['stock_in_line_ids'] ?? []))
            ->map(static fn (int|string $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($stockInId <= 0 || $stockInLineIds === []) {
            throw ValidationException::withMessages([
                'input' => ['stock_in_id and at least one stock_in_line_id are required.'],
            ]);
        }

        return DB::transaction(function () use ($stockInId, $stockInLineIds): array {
            /** @var StockIn $stockIn */
            $stockIn = StockIn::query()
                ->lockForUpdate()
                ->findOrFail($stockInId);

            /** @var \Illuminate\Support\Collection<int, StockInLine> $lines */
            $lines = StockInLine::query()
                ->with('product')
                ->where('stock_in_id', $stockInId)
                ->whereIn('id', $stockInLineIds)
                ->lockForUpdate()
                ->get();

            if ($lines->count() !== count($stockInLineIds)) {
                throw ValidationException::withMessages([
                    'stock_in_line_id' => ['Some selected stock in lines do not belong to the given stock in header.'],
                ]);
            }

            $affectedProductIds = [];
            $deletedLineIds = [];
            $deletedMovementIds = [];
            $affectedPurchaseOrders = [];

            foreach ($lines as $line) {
                $product = $line->product;

                if ($product === null) {
                    throw ValidationException::withMessages([
                        'stock_in_line_id' => [sprintf('Stock in line %d has no linked product.', $line->id)],
                    ]);
                }

                if ($product->requiresSerialNumber()) {
                    throw ValidationException::withMessages([
                        'stock_in_line_id' => [sprintf('Stock in line %d is serialized and cannot be deleted with this command.', $line->id)],
                    ]);
                }

                if (StockItem::query()->where('stock_in_line_id', $line->id)->exists()) {
                    throw ValidationException::withMessages([
                        'stock_in_line_id' => [sprintf('Stock in line %d still has stock_items and cannot be deleted with this command.', $line->id)],
                    ]);
                }

                if (ReturnToSupplierLine::query()->where('stock_in_line_id', $line->id)->exists()) {
                    throw ValidationException::withMessages([
                        'stock_in_line_id' => [sprintf('Stock in line %d is already referenced by return-to-supplier records.', $line->id)],
                    ]);
                }

                $movements = StockMovement::query()
                    ->where('reference_table', 'stock_in_lines')
                    ->where('reference_id', $line->id)
                    ->lockForUpdate()
                    ->get();

                foreach ($movements as $movement) {
                    $deletedMovementIds[] = (int) $movement->id;
                    $movement->delete();
                }

                if ($line->purchase_order_line_id !== null) {
                    /** @var PurchaseOrderLine|null $purchaseOrderLine */
                    $purchaseOrderLine = PurchaseOrderLine::query()
                        ->lockForUpdate()
                        ->find((int) $line->purchase_order_line_id);

                    if ($purchaseOrderLine !== null) {
                        $purchaseOrderLine->received_qty = max(
                            (int) $purchaseOrderLine->received_qty - (int) $line->received_qty,
                            0,
                        );
                        $purchaseOrderLine->save();

                        $affectedPurchaseOrders[(int) $purchaseOrderLine->purchase_order_id] = (int) $purchaseOrderLine->purchase_order_id;
                    }
                } elseif ($stockIn->purchase_order_id !== null) {
                    $affectedPurchaseOrders[(int) $stockIn->purchase_order_id] = (int) $stockIn->purchase_order_id;
                }

                $affectedProductIds[(int) $line->product_id] = (int) $line->product_id;
                $deletedLineIds[] = (int) $line->id;
                $line->delete();
            }

            foreach ($affectedPurchaseOrders as $purchaseOrderId) {
                /** @var PurchaseOrder|null $purchaseOrder */
                $purchaseOrder = PurchaseOrder::query()
                    ->with('lines')
                    ->lockForUpdate()
                    ->find($purchaseOrderId);

                if ($purchaseOrder === null) {
                    continue;
                }

                $isCompleted = $purchaseOrder->lines->isNotEmpty()
                    && $purchaseOrder->lines->every(
                        static fn (PurchaseOrderLine $line): bool => (int) $line->received_qty >= (int) $line->ordered_qty,
                    );

                $purchaseOrder->status = $isCompleted
                    ? PurchaseOrderStatus::Completed
                    : PurchaseOrderStatus::Issued;
                $purchaseOrder->save();
            }

            $deleteEmptyHeader = false;
            $remainingLineCount = StockInLine::query()->where('stock_in_id', $stockInId)->count();
            if ($remainingLineCount === 0 && ! QcDocument::query()->where('stock_in_id', $stockInId)->exists()) {
                $stockIn->delete();
                $deleteEmptyHeader = true;
            }

            $this->stockBalanceUpdater->recomputeForProducts(array_values($affectedProductIds));

            return [
                'stock_in_id' => $stockInId,
                'deleted_line_ids' => $deletedLineIds,
                'deleted_movement_ids' => $deletedMovementIds,
                'affected_product_ids' => array_values($affectedProductIds),
                'deleted_empty_stock_in' => $deleteEmptyHeader,
            ];
        });
    }
}
