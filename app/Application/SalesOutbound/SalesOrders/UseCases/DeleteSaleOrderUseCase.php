<?php

namespace App\Application\SalesOutbound\SalesOrders\UseCases;

use App\Application\Contracts\Repositories\SaleOrderRepository;
use App\Application\Contracts\UseCase;
use App\Domain\SalesOutbound\Enums\SaleOrderStatus;
use Illuminate\Validation\ValidationException;

class DeleteSaleOrderUseCase implements UseCase
{
    public function __construct(private readonly SaleOrderRepository $saleOrders)
    {
    }

    public function execute(mixed $payload = null): mixed
    {
        $id = (int) $payload;
        $saleOrder = $this->saleOrders->findOrFail($id);

        if ($saleOrder->status !== SaleOrderStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => ['Only DRAFT sales orders can be deleted.'],
            ]);
        }

        $this->saleOrders->delete($saleOrder);

        return null;
    }
}
