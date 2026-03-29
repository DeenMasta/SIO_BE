<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\SaleOrderRepository;
use App\Models\SaleOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentSaleOrderRepository implements SaleOrderRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = SaleOrder::query()->with('lines.product');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        return $query->latest('id')->paginate($perPage);
    }

    public function findOrFail(int $id): SaleOrder
    {
        return SaleOrder::query()->with('lines.product')->findOrFail($id);
    }

    public function createWithLines(array $data): SaleOrder
    {
        $lines = $data['lines'];
        unset($data['lines']);

        $saleOrder = SaleOrder::query()->create($data);

        foreach ($lines as $line) {
            $line['subtotal'] = (float) $line['ordered_qty'] * (float) $line['unit_price'];
            $line['fulfilled_qty'] = 0;
            $saleOrder->lines()->create($line);
        }

        return $saleOrder->fresh('lines.product');
    }

    public function update(SaleOrder $so, array $data): SaleOrder
    {
        $lines = $data['lines'] ?? null;
        unset($data['lines']);

        $so->update($data);

        if ($lines !== null) {
            $so->lines()->delete();
            foreach ($lines as $line) {
                $line['subtotal'] = (float) $line['ordered_qty'] * (float) $line['unit_price'];
                $line['fulfilled_qty'] = 0;
                $so->lines()->create($line);
            }
        }

        return $so->fresh('lines.product');
    }

    public function delete(SaleOrder $so): void
    {
        $so->delete();
    }
}
