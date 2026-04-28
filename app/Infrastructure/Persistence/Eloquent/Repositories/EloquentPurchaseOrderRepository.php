<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\PurchaseOrderRepository;
use App\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentPurchaseOrderRepository implements PurchaseOrderRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return PurchaseOrder::query()->with('lines.product')->latest('id')->paginate($perPage);
    }

    public function findOrFail(int $id): PurchaseOrder
    {
        return PurchaseOrder::query()->with('lines.product')->findOrFail($id);
    }

    public function createWithLines(array $data): PurchaseOrder
    {
        $lines = $data['lines'];
        unset($data['lines']);

        $purchaseOrder = PurchaseOrder::query()->create($data);

        foreach ($lines as $line) {
            $line['subtotal'] = (float) $line['ordered_qty'] * (float) $line['unit_price'];
            $line['received_qty'] = 0;
            $purchaseOrder->lines()->create($line);
        }

        return $purchaseOrder->fresh('lines.product');
    }

    public function update(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        $lines = $data['lines'];
        unset($data['lines']);

        $purchaseOrder->update($data);
        $purchaseOrder->lines()->delete();

        foreach ($lines as $line) {
            $line['subtotal'] = (float) $line['ordered_qty'] * (float) $line['unit_price'];
            $line['received_qty'] = 0;
            $purchaseOrder->lines()->create($line);
        }

        return $purchaseOrder->fresh('lines.product');
    }

    public function delete(PurchaseOrder $purchaseOrder): void
    {
        $purchaseOrder->delete();
    }
}
