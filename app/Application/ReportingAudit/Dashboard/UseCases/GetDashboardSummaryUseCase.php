<?php

namespace App\Application\ReportingAudit\Dashboard\UseCases;

use App\Application\Contracts\UseCase;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\QcTransactionLine;
use App\Models\StockBalance;
use App\Models\StockIn;
use App\Models\StockMovement;
use App\Models\StockOut;
use App\Models\StockOutLine;
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

        $totals = StockBalance::query()->selectRaw('SUM(qty_received_pending_qc) as qty_received_pending_qc')
            ->selectRaw('SUM(qty_in_stock) as qty_in_stock')
            ->selectRaw('SUM(qty_delivered) as qty_delivered')
            ->selectRaw('SUM(qty_under_repair) as qty_under_repair')
            ->selectRaw('SUM(qty_returned) as qty_returned')
            ->selectRaw('SUM(qty_returned_to_supplier) as qty_returned_to_supplier')
            ->first();

        $lowStockCount = Product::query()
            ->leftJoin('stock_balances', 'stock_balances.product_id', '=', 'products.id')
            ->where('products.reorder_level', '>', 0)
            ->whereRaw('COALESCE(stock_balances.qty_in_stock, 0) < products.reorder_level')
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

        $qcTrendQuery = QcTransactionLine::query()
            ->join('qc_transactions', 'qc_transactions.id', '=', 'qc_transaction_lines.qc_transaction_id')
            ->selectRaw('qc_transactions.qc_date as date, SUM(qc_transaction_lines.qty_pass) as qty_pass, SUM(qc_transaction_lines.qty_fail) as qty_fail')
            ->groupBy('qc_transactions.qc_date')
            ->orderBy('qc_transactions.qc_date');

        $stockOutTrendQuery = StockOut::query()
            ->join('stock_out_lines', 'stock_out_lines.stock_out_id', '=', 'stock_out.id')
            ->selectRaw('stock_out.stock_out_date as date, COUNT(DISTINCT stock_out.id) as transaction_count, SUM(stock_out_lines.qty) as total_qty')
            ->groupBy('stock_out.stock_out_date')
            ->orderBy('stock_out.stock_out_date');

        $topMovedProductsQuery = StockMovement::query()
            ->selectRaw('product_id, SUM(qty_in + qty_out) as moved_qty')
            ->groupBy('product_id')
            ->orderByDesc('moved_qty')
            ->limit(5);

        if ($dateFrom !== null) {
            $stockInTrendQuery->whereDate('stock_in_date', '>=', (string) $dateFrom);
            $qcTrendQuery->whereDate('qc_transactions.qc_date', '>=', (string) $dateFrom);
            $stockOutTrendQuery->whereDate('stock_out.stock_out_date', '>=', (string) $dateFrom);
            $topMovedProductsQuery->whereDate('movement_datetime', '>=', (string) $dateFrom);
        }
        if ($dateTo !== null) {
            $stockInTrendQuery->whereDate('stock_in_date', '<=', (string) $dateTo);
            $qcTrendQuery->whereDate('qc_transactions.qc_date', '<=', (string) $dateTo);
            $stockOutTrendQuery->whereDate('stock_out.stock_out_date', '<=', (string) $dateTo);
            $topMovedProductsQuery->whereDate('movement_datetime', '<=', (string) $dateTo);
        }

        $topMovedProducts = DB::table('products as p')
            ->joinSub($topMovedProductsQuery, 'mv', function ($join): void {
                $join->on('mv.product_id', '=', 'p.id');
            })
            ->select('mv.product_id', 'p.product_code', 'p.product_name', 'mv.moved_qty')
            ->orderByDesc('mv.moved_qty')
            ->get();

        return [
            'total_products' => Product::query()->count(),
            'total_suppliers' => Supplier::query()->count(),
            'total_customers' => Customer::query()->count(),
            'items_received_pending_qc' => (int) ($totals?->qty_received_pending_qc ?? 0),
            'items_in_stock' => (int) ($totals?->qty_in_stock ?? 0),
            'items_under_repair' => (int) ($totals?->qty_under_repair ?? 0),
            'items_delivered' => (int) ($totals?->qty_delivered ?? 0),
            'items_returned' => (int) ($totals?->qty_returned ?? 0),
            'items_returned_to_supplier' => (int) ($totals?->qty_returned_to_supplier ?? 0),
            'low_stock_count' => $lowStockCount,
            'open_po_count' => $openPoCount,
            'overdue_po_count' => $overduePoCount,
            'movements_today' => StockMovement::query()->whereDate('movement_datetime', today())->count(),
            'stock_in_trend' => $stockInTrendQuery->get(),
            'qc_pass_fail_trend' => $qcTrendQuery->get(),
            'stock_out_trend' => $stockOutTrendQuery->get(),
            'top_moved_products' => $topMovedProducts,
        ];
    }
}
