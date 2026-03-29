<?php

namespace App\Application\SalesOutbound\SalesOrders\UseCases;

use App\Application\Contracts\Repositories\SaleOrderRepository;
use App\Application\Contracts\UseCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListSaleOrdersUseCase implements UseCase
{
    public function __construct(private readonly SaleOrderRepository $saleOrders)
    {
    }

    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $payloadArray = (array) $payload;
        $perPage = (int) ($payloadArray['per_page'] ?? 15);
        $filters = $payloadArray['filters'] ?? [];

        return $this->saleOrders->paginate($perPage > 0 ? $perPage : 15, $filters);
    }
}
