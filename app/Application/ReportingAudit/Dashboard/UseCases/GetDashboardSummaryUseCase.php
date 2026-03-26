<?php

namespace App\Application\ReportingAudit\Dashboard\UseCases;

use App\Application\Contracts\UseCase;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;

class GetDashboardSummaryUseCase implements UseCase
{
    /**
     * @return array<string, int>
     */
    public function execute(mixed $payload = null): array
    {
        $totals = StockBalance::query()->selectRaw('SUM(qty_received_pending_qc) as qty_received_pending_qc')
            ->selectRaw('SUM(qty_in_stock) as qty_in_stock')
            ->selectRaw('SUM(qty_delivered) as qty_delivered')
            ->selectRaw('SUM(qty_under_repair) as qty_under_repair')
            ->selectRaw('SUM(qty_returned) as qty_returned')
            ->selectRaw('SUM(qty_returned_to_supplier) as qty_returned_to_supplier')
            ->first();

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
            'movements_today' => StockMovement::query()->whereDate('movement_datetime', today())->count(),
        ];
    }
}
