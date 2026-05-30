<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\SaleOrderRepository;
use App\Models\SaleOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentSaleOrderRepository implements SaleOrderRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = SaleOrder::query()->with(['customer', 'lines.product']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('so_number', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', function ($customerQuery) use ($search): void {
                        $customerQuery->where('customer_name', 'like', '%'.$search.'%');
                    });
            });
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
            $saleOrder->lines()->create($this->normalizeLine($line));
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
                $so->lines()->create($this->normalizeLine($line));
            }
        }

        return $so->fresh('lines.product');
    }

    public function appendLines(SaleOrder $so, array $lines): SaleOrder
    {
        foreach ($lines as $line) {
            $so->lines()->create($this->normalizeLine($line));
        }

        return $so->fresh('lines.product');
    }

    public function delete(SaleOrder $so): void
    {
        $so->delete();
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function normalizeLine(array $line): array
    {
        $isFree = filter_var($line['is_free'] ?? false, FILTER_VALIDATE_BOOL);
        $unitPrice = $isFree ? 0.0 : (float) $line['unit_price'];

        $line['is_free'] = $isFree;
        $line['unit_price'] = $unitPrice;
        $line['subtotal'] = (float) $line['ordered_qty'] * $unitPrice;
        $line['fulfilled_qty'] = 0;

        return $line;
    }
}
