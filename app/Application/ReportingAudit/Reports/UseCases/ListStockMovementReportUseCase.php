<?php

namespace App\Application\ReportingAudit\Reports\UseCases;

use App\Application\Contracts\UseCase;
use App\Models\StockMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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

        $query = StockMovement::query()->latest('id');

        if (! empty($filters['movement_type'])) {
            $query->where('movement_type', (string) $filters['movement_type']);
        }

        if (! empty($filters['product_id'])) {
            $query->where('product_id', (int) $filters['product_id']);
        }

        if (! empty($filters['stock_item_id'])) {
            $query->where('stock_item_id', (int) $filters['stock_item_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('movement_datetime', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('movement_datetime', '<=', (string) $filters['date_to']);
        }

        return $query;
    }
}
