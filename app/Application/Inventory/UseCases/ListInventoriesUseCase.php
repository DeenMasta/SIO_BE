<?php

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Inventory\InventoryStockQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListInventoriesUseCase implements UseCase
{
    public function __construct(private readonly InventoryStockQuery $inventoryStockQuery)
    {
    }

    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $filters = is_array($payload) ? $payload : [];
        $perPage = (int) ($filters['per_page'] ?? 15);

        $query = $this->inventoryStockQuery->base();
        $this->inventoryStockQuery->applyFilters($query, $filters);
        $this->inventoryStockQuery->applyDefaultSort($query);

        return $query->paginate($perPage > 0 ? $perPage : 15);
    }
}
