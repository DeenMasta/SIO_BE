<?php

namespace App\Application\SalesOutbound\SalesOrders\UseCases;

use App\Application\Contracts\Repositories\SaleOrderRepository;
use App\Application\Contracts\UseCase;
use App\Domain\SalesOutbound\Enums\SaleOrderStatus;
use App\Models\SaleOrder;
use Illuminate\Validation\ValidationException;

class UpdateSaleOrderUseCase implements UseCase
{
    public function __construct(private readonly SaleOrderRepository $saleOrders)
    {
    }

    public function execute(mixed $payload = null): SaleOrder
    {
        $data = (array) $payload;
        $id = (int) $data['id'];
        
        $saleOrder = $this->saleOrders->findOrFail($id);
        
        if ($saleOrder->status !== SaleOrderStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => ['Only DRAFT sales orders can be updated.'],
            ]);
        }

        return $this->saleOrders->update($saleOrder, $data);
    }
}
