<?php

namespace App\Application\QcOutbound\StockOut\UseCases;

use App\Application\Contracts\Repositories\StockOutRepository;
use App\Application\Contracts\UseCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListStockOutsUseCase implements UseCase
{
    public function __construct(private readonly StockOutRepository $stockOuts)
    {
    }

    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $perPage = is_array($payload) ? (int) ($payload['per_page'] ?? 15) : 15;

        return $this->stockOuts->paginate($perPage > 0 ? $perPage : 15);
    }
}
