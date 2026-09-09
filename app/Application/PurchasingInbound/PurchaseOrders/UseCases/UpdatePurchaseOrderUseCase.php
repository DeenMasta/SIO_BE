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
                ->with(['lines.product', 'lines.stockInLines'])
                ->lockForUpdate()
                ->findOrFail($id);

            if (! in_array($purchaseOrder->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Partial], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only DRAFT or PARTIAL purchase orders can be updated.'],
                ]);
            }

            $wasPartial = $purchaseOrder->status === PurchaseOrderStatus::Partial;

            if ($wasPartial) {
                $this->validatePartialUpdate($purchaseOrder, $data);
            }

            $this->productSupplierValidator->validate((int) $data['supplier_id'], $data['lines']);

            $updatedPurchaseOrder = $this->purchaseOrders->update($purchaseOrder, $data);

            if ($wasPartial && $this->isFulfilled($updatedPurchaseOrder)) {
                $updatedPurchaseOrder->update(['status' => PurchaseOrderStatus::Completed]);
            }

            return $updatedPurchaseOrder->fresh('lines.product');
        });
    }

    private function isFulfilled(PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->lines->isNotEmpty()
            && $purchaseOrder->lines->every(
                static fn (\App\Models\PurchaseOrderLine $line): bool => (int) $line->received_qty >= (int) $line->ordered_qty,
            );
    }

    /**
     * PARTIAL POs already have posted receipts. Their received lines must keep
     * their identity, product, and received quantity so Stock In history,
     * stock items, and movement records remain intact.
     *
     * @param array<string, mixed> $data
     */
    private function validatePartialUpdate(PurchaseOrder $purchaseOrder, array $data): void
    {
        if ((int) $data['supplier_id'] !== (int) $purchaseOrder->supplier_id) {
            throw ValidationException::withMessages([
                'supplier_id' => ['The supplier cannot be changed after a purchase order has received stock.'],
            ]);
        }

        $existingLines = $purchaseOrder->lines->keyBy('id');
        $submittedLineIds = [];

        foreach ($data['lines'] as $index => $line) {
            $lineId = (int) ($line['id'] ?? 0);

            if ($lineId === 0) {
                continue;
            }

            if (in_array($lineId, $submittedLineIds, true)) {
                throw ValidationException::withMessages([
                    "lines.$index.id" => ['Each purchase order line can only be submitted once.'],
                ]);
            }

            $existingLine = $existingLines->get($lineId);
            if (! $existingLine instanceof \App\Models\PurchaseOrderLine) {
                throw ValidationException::withMessages([
                    "lines.$index.id" => ['This purchase order line does not belong to the selected purchase order.'],
                ]);
            }

            $submittedLineIds[] = $lineId;
            $hasReceipt = (int) $existingLine->received_qty > 0 || $existingLine->stockInLines->isNotEmpty();

            if (! $hasReceipt) {
                continue;
            }

            if ((int) $line['product_id'] !== (int) $existingLine->product_id) {
                throw ValidationException::withMessages([
                    "lines.$index.product_id" => ['The product cannot be changed on a line that has received stock.'],
                ]);
            }

            if ((int) $line['ordered_qty'] < (int) $existingLine->received_qty) {
                throw ValidationException::withMessages([
                    "lines.$index.ordered_qty" => [
                        sprintf(
                            'Ordered quantity cannot be lower than the %d unit(s) already received.',
                            (int) $existingLine->received_qty,
                        ),
                    ],
                ]);
            }
        }

        $removedReceivedLine = $existingLines->first(function (\App\Models\PurchaseOrderLine $line) use ($submittedLineIds): bool {
            return ! in_array((int) $line->id, $submittedLineIds, true)
                && ((int) $line->received_qty > 0 || $line->stockInLines->isNotEmpty());
        });

        if ($removedReceivedLine !== null) {
            throw ValidationException::withMessages([
                'lines' => ['A purchase order line with received stock cannot be removed.'],
            ]);
        }
    }
}
