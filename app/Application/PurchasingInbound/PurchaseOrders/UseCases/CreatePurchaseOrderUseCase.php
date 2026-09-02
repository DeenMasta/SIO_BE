<?php

namespace App\Application\PurchasingInbound\PurchaseOrders\UseCases;

use App\Application\Contracts\Repositories\PurchaseOrderRepository;
use App\Application\Contracts\UseCase;
use App\Application\PurchasingInbound\PurchaseOrders\PurchaseOrderProductSupplierValidator;
use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

class CreatePurchaseOrderUseCase implements UseCase
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly PurchaseOrderProductSupplierValidator $productSupplierValidator,
    ) {}

    public function execute(mixed $payload = null): PurchaseOrder
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): PurchaseOrder {
            $this->productSupplierValidator->validate((int) $data['supplier_id'], $data['lines']);

            // A PO can only become ISSUED/PARTIAL/COMPLETED through its explicit
            // lifecycle transitions and posted stock-ins.
            $data['status'] = PurchaseOrderStatus::Draft;

            return $this->purchaseOrders->createWithLines($data);
        });
    }
}
