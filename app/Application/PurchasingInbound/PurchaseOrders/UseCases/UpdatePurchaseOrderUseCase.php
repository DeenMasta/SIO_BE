<?php

namespace App\Application\PurchasingInbound\PurchaseOrders\UseCases;

use App\Application\Contracts\Repositories\PurchaseOrderRepository;
use App\Application\Contracts\UseCase;
use App\Application\PurchasingInbound\PurchaseOrders\PurchaseOrderProductSupplierValidator;
use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePurchaseOrderUseCase implements UseCase
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly PurchaseOrderProductSupplierValidator $productSupplierValidator,
    ) {}

    public function execute(mixed $payload = null): PurchaseOrder
    {
        $data = (array) $payload;
        $id = (int) $data['id'];

        return DB::transaction(function () use ($data, $id): PurchaseOrder {
            $purchaseOrder = PurchaseOrder::query()
                ->with('lines.product')
                ->lockForUpdate()
                ->findOrFail($id);

            if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => ['Only DRAFT purchase orders can be updated. Create a correction transaction for an issued or received purchase order.'],
                ]);
            }

            $this->productSupplierValidator->validate((int) $data['supplier_id'], $data['lines']);

            return $this->purchaseOrders->update($purchaseOrder, $data);
        });
    }
}
