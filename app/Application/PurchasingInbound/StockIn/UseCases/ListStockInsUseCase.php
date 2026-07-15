<?php

namespace App\Application\PurchasingInbound\StockIn\UseCases;

use App\Application\Contracts\Repositories\StockInRepository;
use App\Application\Contracts\UseCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListStockInsUseCase implements UseCase
{
    public function __construct(private readonly StockInRepository $stockIns)
    {
    }

    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $filters = is_array($payload) ? $payload : [];
        $perPage = (int) ($filters['per_page'] ?? 15);

        return $this->stockIns->paginate($perPage > 0 ? $perPage : 15, $filters);
    }
}
