<?php

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Support\StockBalanceUpdater;
use App\Models\Product;
use App\Models\PurchaseOrderLine;
use App\Models\QcCheck;
use App\Models\QcDocument;
use App\Models\SaleOrderLine;
use App\Models\StockInLine;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StockOutLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectWrongProductChainUseCase implements UseCase
{
    public function __construct(
        private readonly StockBalanceUpdater $stockBalanceUpdater,
    ) {
    }

    /**
     * @param  array{
     *     purchase_order_line_id:int,
     *     stock_in_line_id:int,
     *     from_product_id:int,
     *     to_product_id:int,
     *     sale_order_line_id?:int|null,
     *     stock_out_line_id?:int|null,
     *     po_date?:string|null,
     *     stock_in_date?:string|null,
     *     qc_date?:string|null,
     *     so_date?:string|null,
     *     stock_out_date?:string|null
     * }  $payload
     * @return array<string, mixed>
     */
    public function execute(mixed $payload = null): array
    {
        $data = (array) $payload;

        $fromProductId = (int) ($data['from_product_id'] ?? 0);
        $toProductId = (int) ($data['to_product_id'] ?? 0);

        if ($fromProductId <= 0 || $toProductId <= 0) {
            throw ValidationException::withMessages([
                'product_id' => ['Both from_product_id and to_product_id are required.'],
            ]);
        }

        if ($fromProductId === $toProductId) {
            throw ValidationException::withMessages([
                'product_id' => ['from_product_id and to_product_id must be different.'],
            ]);
        }

        /** @var Product $fromProduct */
        $fromProduct = Product::query()->findOrFail($fromProductId);
        /** @var Product $toProduct */
        $toProduct = Product::query()->findOrFail($toProductId);

        if ($fromProduct->requiresSerialNumber() !== $toProduct->requiresSerialNumber()) {
            throw ValidationException::withMessages([
                'product_id' => ['Cannot swap between serialized and non-serialized products.'],
            ]);
        }

        return DB::transaction(function () use ($data, $fromProduct, $toProduct, $fromProductId, $toProductId): array {
            /** @var PurchaseOrderLine $purchaseOrderLine */
            $purchaseOrderLine = PurchaseOrderLine::query()
                ->with('purchaseOrder')
                ->lockForUpdate()
                ->findOrFail((int) $data['purchase_order_line_id']);

            if ((int) $purchaseOrderLine->product_id !== $fromProductId) {
                throw ValidationException::withMessages([
                    'purchase_order_line_id' => ['Purchase order line does not point to from_product_id.'],
                ]);
            }

            /** @var StockInLine $stockInLine */
            $stockInLine = StockInLine::query()
                ->with('stockIn')
                ->lockForUpdate()
                ->findOrFail((int) $data['stock_in_line_id']);

            if ((int) $stockInLine->product_id !== $fromProductId) {
                throw ValidationException::withMessages([
                    'stock_in_line_id' => ['Stock in line does not point to from_product_id.'],
                ]);
            }

            if ((int) $stockInLine->purchase_order_line_id !== (int) $purchaseOrderLine->id) {
                throw ValidationException::withMessages([
                    'stock_in_line_id' => ['Stock in line is not linked to the selected purchase order line.'],
                ]);
            }

            $saleOrderLine = null;
            if (! empty($data['sale_order_line_id'])) {
                /** @var SaleOrderLine $saleOrderLine */
                $saleOrderLine = SaleOrderLine::query()
                    ->with('saleOrder')
                    ->lockForUpdate()
                    ->findOrFail((int) $data['sale_order_line_id']);

                if ((int) $saleOrderLine->product_id !== $fromProductId) {
                    throw ValidationException::withMessages([
                        'sale_order_line_id' => ['Sale order line does not point to from_product_id.'],
                    ]);
                }
            }

            $stockOutLine = null;
            if (! empty($data['stock_out_line_id'])) {
                /** @var StockOutLine $stockOutLine */
                $stockOutLine = StockOutLine::query()
                    ->with('lineItems', 'saleOrderLine', 'saleOrderLine.saleOrder')
                    ->lockForUpdate()
                    ->findOrFail((int) $data['stock_out_line_id']);

                if ((int) $stockOutLine->product_id !== $fromProductId) {
                    throw ValidationException::withMessages([
                        'stock_out_line_id' => ['Stock out line does not point to from_product_id.'],
                    ]);
                }

                if ($saleOrderLine !== null && (int) $stockOutLine->sale_order_line_id !== (int) $saleOrderLine->id) {
                    throw ValidationException::withMessages([
                        'stock_out_line_id' => ['Stock out line is not linked to the selected sale order line.'],
                    ]);
                }
            }

            $stockItems = StockItem::query()
                ->where('stock_in_line_id', $stockInLine->id)
                ->lockForUpdate()
                ->get();

            $stockItemIds = $stockItems->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $isSerialized = $fromProduct->requiresSerialNumber();

            if ($isSerialized && $stockItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'stock_in_line_id' => ['Serialized product correction requires linked stock_items, but none were found.'],
                ]);
            }

            if (! $isSerialized && $stockItems->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'stock_in_line_id' => ['Non-serialized product correction found unexpected stock_items.'],
                ]);
            }

            $qcChecks = collect();
            if ($stockItemIds !== []) {
                $qcChecks = QcCheck::query()
                    ->whereIn('stock_item_id', $stockItemIds)
                    ->lockForUpdate()
                    ->get();
            }

            $qcDocumentIds = $qcChecks
                ->pluck('qc_document_id')
                ->filter()
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            $movements = $this->loadRelevantMovements(
                stockItemIds: $stockItemIds,
                stockInLineId: (int) $stockInLine->id,
                qcCheckIds: $qcChecks->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
                stockOutLineId: $stockOutLine?->id,
                stockOutLineItemIds: $stockOutLine?->lineItems->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all() ?? [],
            );

            $this->applyHeaderDateCorrections(
                purchaseOrderLine: $purchaseOrderLine,
                stockInLine: $stockInLine,
                saleOrderLine: $saleOrderLine,
                stockOutLine: $stockOutLine,
                qcDocumentIds: $qcDocumentIds,
                data: $data,
            );

            $purchaseOrderLine->update(['product_id' => $toProductId]);
            $stockInLine->update(['product_id' => $toProductId]);

            if ($saleOrderLine !== null) {
                $saleOrderLine->update(['product_id' => $toProductId]);
            }

            if ($stockOutLine !== null) {
                $stockOutLine->update(['product_id' => $toProductId]);
            }

            if ($stockItemIds !== []) {
                StockItem::query()
                    ->whereIn('id', $stockItemIds)
                    ->update(['product_id' => $toProductId]);
            }

            $this->updateMovements(
                movements: $movements,
                toProductId: $toProductId,
                stockInDate: $data['stock_in_date'] ?? null,
                qcDate: $data['qc_date'] ?? null,
                stockOutDate: $data['stock_out_date'] ?? null,
            );

            $this->stockBalanceUpdater->recomputeForProducts([$fromProductId, $toProductId]);

            return [
                'from_product' => [
                    'id' => $fromProduct->id,
                    'code' => $fromProduct->product_code,
                    'name' => $fromProduct->product_name,
                ],
                'to_product' => [
                    'id' => $toProduct->id,
                    'code' => $toProduct->product_code,
                    'name' => $toProduct->product_name,
                ],
                'purchase_order_id' => (int) $purchaseOrderLine->purchase_order_id,
                'purchase_order_line_id' => (int) $purchaseOrderLine->id,
                'stock_in_id' => (int) $stockInLine->stock_in_id,
                'stock_in_line_id' => (int) $stockInLine->id,
                'qc_document_ids' => $qcDocumentIds,
                'sale_order_id' => $saleOrderLine?->sale_order_id,
                'sale_order_line_id' => $saleOrderLine?->id,
                'stock_out_id' => $stockOutLine?->stock_out_id,
                'stock_out_line_id' => $stockOutLine?->id,
                'updated_stock_item_ids' => $stockItemIds,
                'updated_stock_movement_ids' => $movements->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            ];
        });
    }

    /**
     * @param  array<int, int>  $stockItemIds
     * @param  array<int, int>  $qcCheckIds
     * @param  array<int, int>  $stockOutLineItemIds
     * @return Collection<int, StockMovement>
     */
    private function loadRelevantMovements(
        array $stockItemIds,
        int $stockInLineId,
        array $qcCheckIds,
        ?int $stockOutLineId,
        array $stockOutLineItemIds,
    ): Collection {
        return StockMovement::query()
            ->where(function ($query) use ($stockItemIds, $stockInLineId, $qcCheckIds, $stockOutLineId, $stockOutLineItemIds): void {
                if ($stockItemIds !== []) {
                    $query->orWhereIn('stock_item_id', $stockItemIds);
                }

                $query->orWhere(function ($subQuery) use ($stockInLineId): void {
                    $subQuery
                        ->where('reference_table', 'stock_in_lines')
                        ->where('reference_id', $stockInLineId);
                });

                if ($qcCheckIds !== []) {
                    $query->orWhere(function ($subQuery) use ($qcCheckIds): void {
                        $subQuery
                            ->where('reference_table', 'qc_items')
                            ->whereIn('reference_id', $qcCheckIds);
                    });
                }

                if ($stockOutLineId !== null) {
                    $query->orWhere(function ($subQuery) use ($stockOutLineId): void {
                        $subQuery
                            ->where('reference_table', 'stock_out_lines')
                            ->where('reference_id', $stockOutLineId);
                    });
                }

                if ($stockOutLineItemIds !== []) {
                    $query->orWhere(function ($subQuery) use ($stockOutLineItemIds): void {
                        $subQuery
                            ->where('reference_table', 'stock_out_line_items')
                            ->whereIn('reference_id', $stockOutLineItemIds);
                    });
                }
            })
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  array<int, int>  $qcDocumentIds
     * @param  array<string, mixed>  $data
     */
    private function applyHeaderDateCorrections(
        PurchaseOrderLine $purchaseOrderLine,
        StockInLine $stockInLine,
        ?SaleOrderLine $saleOrderLine,
        ?StockOutLine $stockOutLine,
        array $qcDocumentIds,
        array $data,
    ): void {
        if (! empty($data['po_date'])) {
            $purchaseOrderLine->purchaseOrder?->update([
                'po_date' => $this->normalizeDate((string) $data['po_date']),
            ]);
        }

        if (! empty($data['stock_in_date'])) {
            $stockInLine->stockIn?->update([
                'stock_in_date' => $this->normalizeDate((string) $data['stock_in_date']),
            ]);
        }

        if (! empty($data['qc_date']) && $qcDocumentIds !== []) {
            QcDocument::query()
                ->whereIn('id', $qcDocumentIds)
                ->update([
                    'date' => $this->normalizeDate((string) $data['qc_date']),
                ]);
        }

        if (! empty($data['so_date']) && $saleOrderLine?->saleOrder !== null) {
            $saleOrderLine->saleOrder->update([
                'so_date' => $this->normalizeDate((string) $data['so_date']),
            ]);
        }

        if (! empty($data['stock_out_date']) && $stockOutLine?->stockOut !== null) {
            $stockOutLine->stockOut->update([
                'stock_out_date' => $this->normalizeDate((string) $data['stock_out_date']),
            ]);
        }
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     */
    private function updateMovements(
        Collection $movements,
        int $toProductId,
        ?string $stockInDate,
        ?string $qcDate,
        ?string $stockOutDate,
    ): void {
        $normalizedStockInDate = $stockInDate !== null && trim($stockInDate) !== ''
            ? $this->normalizeDate($stockInDate)
            : null;
        $normalizedQcDate = $qcDate !== null && trim($qcDate) !== ''
            ? $this->normalizeDate($qcDate)
            : null;
        $normalizedStockOutDate = $stockOutDate !== null && trim($stockOutDate) !== ''
            ? $this->normalizeDate($stockOutDate)
            : null;

        foreach ($movements as $movement) {
            $movement->product_id = $toProductId;

            $targetDate = match ($movement->reference_table) {
                'stock_in_lines' => $normalizedStockInDate,
                'qc_items' => $normalizedQcDate,
                'stock_out_lines', 'stock_out_line_items' => $normalizedStockOutDate,
                default => null,
            };

            if ($targetDate !== null) {
                $movement->movement_datetime = $this->replaceDatePreservingTime(
                    $movement->movement_datetime,
                    $targetDate,
                );
            }

            $movement->save();
        }
    }

    private function normalizeDate(string $value): string
    {
        return Carbon::parse($value)->toDateString();
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
