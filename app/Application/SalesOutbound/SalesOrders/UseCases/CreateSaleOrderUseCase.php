<?php

namespace App\Application\SalesOutbound\SalesOrders\UseCases;

use App\Application\Contracts\Repositories\SaleOrderRepository;
use App\Application\Contracts\UseCase;
use App\Models\SaleOrder;

class CreateSaleOrderUseCase implements UseCase
{
    public function __construct(private readonly SaleOrderRepository $saleOrders)
    {
    }

    public function execute(mixed $payload = null): SaleOrder
    {
        return $this->saleOrders->createWithLines((array) $payload);
    }
}
