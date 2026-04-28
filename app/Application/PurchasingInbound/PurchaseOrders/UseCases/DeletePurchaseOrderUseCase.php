<?php

namespace App\Application\PurchasingInbound\PurchaseOrders\UseCases;

use App\Application\Contracts\Repositories\PurchaseOrderRepository;
use App\Application\Contracts\UseCase;
use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use Illuminate\Validation\ValidationException;

class DeletePurchaseOrderUseCase implements UseCase
{
    public function __construct(private readonly PurchaseOrderRepository $purchaseOrders)
    {
    }

    public function execute(mixed $payload = null): mixed
    {
        $id = (int) $payload;
        $purchaseOrder = $this->purchaseOrders->findOrFail($id);

        $canDelete = in_array($purchaseOrder->status, [
            PurchaseOrderStatus::Draft,
            PurchaseOrderStatus::Cancelled,
        ], true);

        if (! $canDelete) {
            throw ValidationException::withMessages([
                'status' => ['Only DRAFT or CANCELLED purchase orders can be deleted.'],
            ]);
        }

        $this->purchaseOrders->delete($purchaseOrder);

        return null;
    }
}
