<?php

namespace App\Application\PurchasingInbound\PurchaseOrders\UseCases;

use App\Application\Contracts\Repositories\PurchaseOrderRepository;
use App\Application\Contracts\UseCase;
use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Validation\ValidationException;

class UpdatePurchaseOrderUseCase implements UseCase
{
    public function __construct(private readonly PurchaseOrderRepository $purchaseOrders)
    {
    }

    public function execute(mixed $payload = null): PurchaseOrder
    {
        $data = (array) $payload;
        $id = (int) $data['id'];

        $purchaseOrder = $this->purchaseOrders->findOrFail($id);

        if (! in_array($purchaseOrder->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Issued], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only DRAFT or ISSUED purchase orders can be updated.'],
            ]);
        }

        return $this->purchaseOrders->update($purchaseOrder, $data);
    }
}
