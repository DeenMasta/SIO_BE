<?php

namespace App\Application\Support;

use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class StockBalanceUpdater
{
    /**
     * @param  array<int, int|string>  $productIds
     */
    public function recomputeForProducts(array $productIds): void
    {
        $ids = collect($productIds)
            ->map(static fn (int|string $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $serializedByProduct = StockItem::query()
            ->whereIn('product_id', $ids)
            ->selectRaw('product_id')
            ->selectRaw("SUM(CASE WHEN current_status IN ('IN_STOCK', 'RECEIVED') THEN 1 ELSE 0 END) as qty_in_stock_serialized")
            ->selectRaw("SUM(CASE WHEN current_status = 'DELIVERED' THEN 1 ELSE 0 END) as qty_delivered")
            ->selectRaw("SUM(CASE WHEN current_status = 'UNDER_REPAIR' THEN 1 ELSE 0 END) as qty_under_repair")
            ->selectRaw("SUM(CASE WHEN current_status = 'RETURNED' THEN 1 ELSE 0 END) as qty_returned")
            ->selectRaw("SUM(CASE WHEN current_status = 'RETURNED_TO_SUPPLIER' THEN 1 ELSE 0 END) as qty_returned_to_supplier")
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $nonSerializedByProduct = StockMovement::query()
            ->whereNull('stock_item_id')
            ->whereIn('product_id', $ids)
            ->selectRaw('product_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN to_status IN ('IN_STOCK', 'RECEIVED') THEN qty_in ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN from_status IN ('IN_STOCK', 'RECEIVED') THEN qty_out ELSE 0 END), 0) as qty_in_stock_non_serialized")
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $timestamp = now();

        $rows = $ids->map(function (int $productId) use ($serializedByProduct, $nonSerializedByProduct, $timestamp): array {
            $serialized = $serializedByProduct->get($productId);
            $nonSerializedRaw = (int) (($nonSerializedByProduct->get($productId)->qty_in_stock_non_serialized) ?? 0);

            return [
                'product_id' => $productId,
                'qty_in_stock' => (int) ($serialized->qty_in_stock_serialized ?? 0) + max($nonSerializedRaw, 0),
                'qty_delivered' => (int) ($serialized->qty_delivered ?? 0),
                'qty_under_repair' => (int) ($serialized->qty_under_repair ?? 0),
                'qty_returned' => (int) ($serialized->qty_returned ?? 0),
                'qty_returned_to_supplier' => (int) ($serialized->qty_returned_to_supplier ?? 0),
                'last_computed_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        })->all();

        DB::table('stock_balances')->upsert(
            $rows,
            ['product_id'],
            [
                'qty_in_stock',
                'qty_delivered',
                'qty_under_repair',
                'qty_returned',
                'qty_returned_to_supplier',
                'last_computed_at',
                'updated_at',
            ],
        );
    }
}
