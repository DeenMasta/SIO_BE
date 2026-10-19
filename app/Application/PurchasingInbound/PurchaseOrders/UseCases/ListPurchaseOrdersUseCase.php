<?php

namespace App\Application\PurchasingInbound\PurchaseOrders\UseCases;

use App\Application\Contracts\Repositories\PurchaseOrderRepository;
use App\Application\Contracts\UseCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListPurchaseOrdersUseCase implements UseCase
{
    public function __construct(private readonly PurchaseOrderRepository $purchaseOrders)
    {
    }

    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $filters = is_array($payload) ? $payload : [];
        $perPage = (int) ($filters['per_page'] ?? 15);

        return $this->purchaseOrders->paginate($perPage > 0 ? $perPage : 15, $filters);
    }
}
