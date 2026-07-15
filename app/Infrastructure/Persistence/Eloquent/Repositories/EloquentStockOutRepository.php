<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\StockOutRepository;
use App\Models\StockOut;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentStockOutRepository implements StockOutRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $search = trim((string) ($filters['q'] ?? ''));

        return StockOut::query()
            ->with([
                'customer',
                'saleOrder',
                'lines.product',
                'lines.saleOrderLine',
                'lines.settledSaleOrderLines',
                'lines.lineItems.stockItem',
                'lines.lineItems.settledSaleOrderLine',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('stock_out_number', 'like', '%'.$search.'%')
                        ->orWhere('invoice_number', 'like', '%'.$search.'%')
                        ->orWhereHas('saleOrder', function (Builder $saleOrderQuery) use ($search): void {
                            $saleOrderQuery->where('so_number', 'like', '%'.$search.'%')
                                ->orWhere('invoice_number', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('customer', function (Builder $customerQuery) use ($search): void {
                            $customerQuery->where('customer_name', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('lines.product', function (Builder $productQuery) use ($search): void {
                            $productQuery->where('product_code', 'like', '%'.$search.'%')
                                ->orWhere('product_name', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('lines.lineItems.stockItem', function (Builder $stockItemQuery) use ($search): void {
                            $stockItemQuery->where('serial_number', 'like', '%'.$search.'%');
                        });
                });
            })
            ->latest('id')
            ->paginate($perPage);
    }

    public function findOrFail(int $id): StockOut
    {
        return StockOut::query()
            ->with([
                'saleOrder',
                'lines.product',
                'lines.saleOrderLine',
                'lines.settledSaleOrderLines',
                'lines.lineItems.stockItem',
                'lines.lineItems.settledSaleOrderLine',
            ])
            ->findOrFail($id);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?StockOut
    {
        return StockOut::query()
            ->with([
                'saleOrder',
                'lines.product',
                'lines.saleOrderLine',
                'lines.settledSaleOrderLines',
                'lines.lineItems.stockItem',
                'lines.lineItems.settledSaleOrderLine',
            ])
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function create(array $data): StockOut
    {
        return StockOut::query()->create($data);
    }
}
