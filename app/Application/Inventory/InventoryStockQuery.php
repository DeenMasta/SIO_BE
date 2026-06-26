<?php

namespace App\Application\Inventory;

use App\Models\Product;
use App\Models\StockItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class InventoryStockQuery
{
    public function base(): Builder
    {
        $serializedAvailable = StockItem::query()
            ->selectRaw('product_id, COUNT(*) as qty_available_serialized', [])
            ->where('current_status', 'IN_STOCK')
            ->where('is_available', true)
            ->where('qc_status', 'PASSED')
            ->groupBy('product_id');

        $nonSerializedAvailable = DB::table('stock_movements')
            ->selectRaw('product_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN to_status = 'IN_STOCK' THEN qty_in ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN from_status = 'IN_STOCK' THEN qty_out ELSE 0 END), 0) as qty_available_non_serialized")
            ->whereNull('stock_item_id')
            ->groupBy('product_id');

        $availableQty = $this->availableQtyExpression();

        return Product::query()
            ->from('products', 'p')
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->leftJoin('stock_balances as sb', 'sb.product_id', '=', 'p.id')
            ->leftJoinSub($serializedAvailable, 'sa', function ($join): void {
                $join->on('sa.product_id', '=', 'p.id');
            })
            ->leftJoinSub($nonSerializedAvailable, 'ns', function ($join): void {
                $join->on('ns.product_id', '=', 'p.id');
            })
            ->select([
                'p.id as product_id',
                'p.product_code',
                'p.product_name',
                'p.product_model',
                'p.product_type',
                'p.requires_serial_number',
                'p.supplier_id',
                's.supplier_code',
                's.supplier_name',
                'p.uom',
                'p.reorder_level',
                'p.status',
                DB::raw('COALESCE(sb.qty_in_stock, 0) as qty_in_stock'),
                DB::raw('COALESCE(sb.qty_delivered, 0) as qty_delivered'),
                DB::raw('COALESCE(sb.qty_internal_use, 0) as qty_internal_use'),
                DB::raw('COALESCE(sb.qty_under_repair, 0) as qty_under_repair'),
                DB::raw('COALESCE(sb.qty_returned, 0) as qty_returned'),
                DB::raw('COALESCE(sb.qty_returned_to_supplier, 0) as qty_returned_to_supplier'),
                DB::raw('COALESCE(sa.qty_available_serialized, 0) as qty_available_serialized'),
                DB::raw('sb.last_computed_at'),
                DB::raw($availableQty.' as qty_available'),
                DB::raw($this->stockStatusExpression().' as stock_status'),
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('p.product_code', 'like', '%'.$search.'%')
                    ->orWhere('p.product_name', 'like', '%'.$search.'%')
                    ->orWhere('p.product_model', 'like', '%'.$search.'%')
                    ->orWhere('s.supplier_code', 'like', '%'.$search.'%')
                    ->orWhere('s.supplier_name', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['product_id'])) {
            $query->where('p.id', (int) $filters['product_id']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('p.supplier_id', (int) $filters['supplier_id']);
        }

        if (! empty($filters['stock_status'])) {
            $query->whereRaw($this->stockStatusExpression().' = ?', [(string) $filters['stock_status']]);
        }

        return $query;
    }

    public function applyDefaultSort(Builder $query): Builder
    {
        return $query
            ->orderByRaw("{$this->availableQtyExpression()} DESC")
            ->orderBy('p.product_code');
    }

    private function availableQtyExpression(): string
    {
        return "CASE WHEN p.requires_serial_number = 1 THEN COALESCE(sa.qty_available_serialized, 0) ELSE CASE WHEN ns.qty_available_non_serialized IS NULL THEN COALESCE(sb.qty_in_stock, 0) WHEN ns.qty_available_non_serialized < 0 THEN 0 ELSE ns.qty_available_non_serialized END END";
    }

    private function stockStatusExpression(): string
    {
        $availableQty = $this->availableQtyExpression();

        return "CASE WHEN {$availableQty} <= 0 THEN 'out_of_stock' WHEN p.reorder_level > 0 AND {$availableQty} < p.reorder_level THEN 'low_stock' ELSE 'in_stock' END";
    }
}
