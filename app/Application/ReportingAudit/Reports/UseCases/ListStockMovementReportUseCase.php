<?php

namespace App\Application\ReportingAudit\Reports\UseCases;

use App\Application\Contracts\UseCase;
use App\Models\StockMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ListStockMovementReportUseCase implements UseCase
{
    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $filters = (array) $payload;
        $perPage = (int) ($filters['per_page'] ?? 15);

        return $this->buildQuery($filters)->paginate($perPage > 0 ? $perPage : 15);
    }

    public function exportRows(array $filters, int $limit = 5000): Collection
    {
        return $this->buildQuery($filters)
            ->limit($limit > 0 ? $limit : 5000)
            ->get();
    }

    private function buildQuery(array $filters): Builder
    {
        $query = StockMovement::query()
            ->leftJoin('products as p', 'p.id', '=', 'stock_movements.product_id')
            ->leftJoin('stock_items as si', 'si.id', '=', 'stock_movements.stock_item_id')
            ->leftJoin('stock_out_line_items as soli', function ($join): void {
                $join->on('soli.id', '=', 'stock_movements.reference_id')
                    ->where('stock_movements.reference_table', '=', 'stock_out_line_items');
            })
            ->leftJoin('stock_out_lines as sol_item', 'sol_item.id', '=', 'soli.stock_out_line_id')
            ->leftJoin('stock_out as so_item', 'so_item.id', '=', 'sol_item.stock_out_id')
            ->leftJoin('stock_out_lines as sol', function ($join): void {
                $join->on('sol.id', '=', 'stock_movements.reference_id')
                    ->where('stock_movements.reference_table', '=', 'stock_out_lines');
            })
            ->leftJoin('stock_out as so', 'so.id', '=', 'sol.stock_out_id')
            ->leftJoin('customers as c_item', 'c_item.id', '=', 'so_item.customer_id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->select([
                'stock_movements.*',
                'p.product_code',
                'p.product_name',
                'si.serial_number',
                DB::raw('COALESCE(so_item.customer_id, so.customer_id) as delivered_customer_id'),
                DB::raw('COALESCE(c_item.customer_name, c.customer_name) as delivered_customer_name'),
            ])
            ->latest('stock_movements.id');

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('stock_movements.movement_type', 'like', '%'.$search.'%')
                    ->orWhere('stock_movements.reference_table', 'like', '%'.$search.'%')
                    ->orWhere('stock_movements.reference_id', 'like', '%'.$search.'%')
                    ->orWhere('stock_movements.remarks', 'like', '%'.$search.'%')
                    ->orWhere('p.product_code', 'like', '%'.$search.'%')
                    ->orWhere('p.product_name', 'like', '%'.$search.'%')
                    ->orWhere('si.serial_number', 'like', '%'.$search.'%')
                    ->orWhere('c_item.customer_name', 'like', '%'.$search.'%')
                    ->orWhere('c.customer_name', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['movement_type'])) {
            $query->where('stock_movements.movement_type', (string) $filters['movement_type']);
        }

        if (! empty($filters['product_id'])) {
            $query->where('stock_movements.product_id', (int) $filters['product_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('stock_movements.movement_datetime', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('stock_movements.movement_datetime', '<=', (string) $filters['date_to']);
        }

        return $query;
    }
}
