<?php

namespace App\Application\ReportingAudit\Dashboard\UseCases;

use App\Application\Contracts\UseCase;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\QcDocument;
use App\Models\StockIn;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StockOut;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class GetDashboardSummaryUseCase implements UseCase
{
    /**
     * @return array<string, mixed>
     */
    public function execute(mixed $payload = null): array
    {
        $filters = (array) $payload;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $totals = StockItem::query()
            ->selectRaw("SUM(CASE WHEN current_status = 'RECEIVED' THEN 1 ELSE 0 END) as qty_received_pending_qc")
            ->selectRaw("SUM(CASE WHEN current_status = 'IN_STOCK' THEN 1 ELSE 0 END) as qty_in_stock")
            ->selectRaw("SUM(CASE WHEN current_status = 'DELIVERED' THEN 1 ELSE 0 END) as qty_delivered")
            ->selectRaw("SUM(CASE WHEN current_status = 'UNDER_REPAIR' THEN 1 ELSE 0 END) as qty_under_repair")
            ->selectRaw("SUM(CASE WHEN current_status = 'RETURNED' THEN 1 ELSE 0 END) as qty_returned")
            ->selectRaw("SUM(CASE WHEN current_status = 'RETURNED_TO_SUPPLIER' THEN 1 ELSE 0 END) as qty_returned_to_supplier")
            ->first();

        $nonSerializedTotals = StockMovement::query()
            ->whereNull('stock_item_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN to_status = 'IN_STOCK' THEN qty_in ELSE 0 END), 0) as qty_in_stock_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN from_status = 'IN_STOCK' THEN qty_out ELSE 0 END), 0) as qty_in_stock_out")
            ->first();

        $serializedInStockByProduct = StockItem::query()
            ->selectRaw('product_id, SUM(CASE WHEN current_status = \'IN_STOCK\' THEN 1 ELSE 0 END) as serialized_in_stock')
            ->groupBy('product_id');

        $nonSerializedInStockByProduct = StockMovement::query()
            ->whereNull('stock_item_id')
            ->selectRaw("product_id, COALESCE(SUM(CASE WHEN to_status = 'IN_STOCK' THEN qty_in ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN from_status = 'IN_STOCK' THEN qty_out ELSE 0 END), 0) as non_serialized_in_stock")
            ->groupBy('product_id');

        $lowStockCount = Product::query()
            ->leftJoinSub($serializedInStockByProduct, 'sis', function ($join): void {
                $join->on('sis.product_id', '=', 'products.id');
            })
            ->leftJoinSub($nonSerializedInStockByProduct, 'ns', function ($join): void {
                $join->on('ns.product_id', '=', 'products.id');
            })
            ->where('products.reorder_level', '>', 0)
            ->whereRaw('COALESCE(sis.serialized_in_stock, 0) + CASE WHEN COALESCE(ns.non_serialized_in_stock, 0) < 0 THEN 0 ELSE COALESCE(ns.non_serialized_in_stock, 0) END < products.reorder_level')
            ->count();

        $openPoCount = PurchaseOrder::query()->whereIn('status', ['DRAFT', 'ISSUED'])->count();
        $overduePoCount = PurchaseOrder::query()
            ->whereIn('status', ['DRAFT', 'ISSUED'])
            ->whereDate('expected_delivery_date', '<', today())
            ->count();

        $stockInTrendQuery = StockIn::query()
            ->selectRaw('stock_in_date as date, COUNT(*) as transaction_count')
            ->groupBy('stock_in_date')
            ->orderBy('stock_in_date');

        $stockOutTrendQuery = StockOut::query()
            ->join('stock_out_lines', 'stock_out_lines.stock_out_id', '=', 'stock_out.id')
            ->selectRaw('stock_out.stock_out_date as date, COUNT(DISTINCT stock_out.id) as transaction_count, SUM(stock_out_lines.qty) as total_qty')
            ->groupBy('stock_out.stock_out_date')
            ->orderBy('stock_out.stock_out_date');

        $qcPassFailTrendQuery = QcDocument::query()
            ->leftJoin('qc_items', 'qc_items.qc_document_id', '=', 'quality_checks.id')
            ->selectRaw('quality_checks.date as date')
            ->selectRaw("SUM(CASE WHEN qc_items.result = 'PASSED' THEN 1 ELSE 0 END) as qty_pass")
            ->selectRaw("SUM(CASE WHEN qc_items.result IN ('FAILED', 'PARTIAL') THEN 1 ELSE 0 END) as qty_fail")
            ->groupBy('quality_checks.date')
            ->orderBy('quality_checks.date');

        $topMovedProductsQuery = StockMovement::query()
            ->selectRaw('product_id, SUM(qty_in + qty_out) as moved_qty')
            ->groupBy('product_id')
            ->orderByDesc('moved_qty')
            ->limit(5);

        if ($dateFrom !== null) {
            $stockInTrendQuery->whereDate('stock_in_date', '>=', (string) $dateFrom);
            $stockOutTrendQuery->whereDate('stock_out.stock_out_date', '>=', (string) $dateFrom);
            $qcPassFailTrendQuery->whereDate('quality_checks.date', '>=', (string) $dateFrom);
            $topMovedProductsQuery->whereDate('movement_datetime', '>=', (string) $dateFrom);
        }
        if ($dateTo !== null) {
            $stockInTrendQuery->whereDate('stock_in_date', '<=', (string) $dateTo);
            $stockOutTrendQuery->whereDate('stock_out.stock_out_date', '<=', (string) $dateTo);
            $qcPassFailTrendQuery->whereDate('quality_checks.date', '<=', (string) $dateTo);
            $topMovedProductsQuery->whereDate('movement_datetime', '<=', (string) $dateTo);
        }

        $topMovedProducts = DB::table('products as p')
            ->joinSub($topMovedProductsQuery, 'mv', function ($join): void {
                $join->on('mv.product_id', '=', 'p.id');
            })
            ->select('mv.product_id', 'p.product_code', 'p.product_name', 'mv.moved_qty')
            ->orderByDesc('mv.moved_qty')
            ->get();

        $itemsInStock =
            (int) ($totals?->qty_in_stock ?? 0)
            + max((int) ($nonSerializedTotals?->qty_in_stock_in ?? 0) - (int) ($nonSerializedTotals?->qty_in_stock_out ?? 0), 0);

        return [
            'total_products' => Product::query()->count(),
            'total_suppliers' => Supplier::query()->count(),
            'total_customers' => Customer::query()->count(),
            'items_received_pending_qc' => (int) ($totals?->qty_received_pending_qc ?? 0),
            'items_in_stock' => $itemsInStock,
            'items_under_repair' => (int) ($totals?->qty_under_repair ?? 0),
            'items_delivered' => (int) ($totals?->qty_delivered ?? 0),
            'items_returned' => (int) ($totals?->qty_returned ?? 0),
            'items_returned_to_supplier' => (int) ($totals?->qty_returned_to_supplier ?? 0),
            'low_stock_count' => $lowStockCount,
            'open_po_count' => $openPoCount,
            'overdue_po_count' => $overduePoCount,
            'movements_today' => StockMovement::query()->whereDate('movement_datetime', today())->count(),
            'stock_in_trend' => $stockInTrendQuery->get(),
            'qc_pass_fail_trend' => $qcPassFailTrendQuery->get(),
            'stock_out_trend' => $stockOutTrendQuery->get(),
            'top_moved_products' => $topMovedProducts,
        ];
    }
}
