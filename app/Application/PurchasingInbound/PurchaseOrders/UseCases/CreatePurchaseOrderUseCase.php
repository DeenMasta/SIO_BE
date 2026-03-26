<?php

namespace App\Application\PurchasingInbound\PurchaseOrders\UseCases;

use App\Application\Contracts\Repositories\PurchaseOrderRepository;
use App\Application\Contracts\UseCase;
use App\Models\PurchaseOrder;

class CreatePurchaseOrderUseCase implements UseCase
{
    public function __construct(private readonly PurchaseOrderRepository $purchaseOrders)
    {
    }

    public function execute(mixed $payload = null): PurchaseOrder
    {
        return $this->purchaseOrders->createWithLines((array) $payload);
    }
}
