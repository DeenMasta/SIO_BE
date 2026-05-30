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

        if (! in_array($saleOrder->status, [SaleOrderStatus::Draft, SaleOrderStatus::Confirmed, SaleOrderStatus::Fulfilled], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only DRAFT, CONFIRMED, or FULFILLED sales orders can be updated.'],
            ]);
        }

        if (in_array($saleOrder->status, [SaleOrderStatus::Confirmed, SaleOrderStatus::Fulfilled], true) && array_key_exists('lines', $data)) {
            throw ValidationException::withMessages([
                'lines' => ['Only DRAFT sales orders allow full line item editing. Use addon-lines for CONFIRMED or FULFILLED orders.'],
            ]);
        }

        return $this->saleOrders->update($saleOrder, $data);
    }
}
