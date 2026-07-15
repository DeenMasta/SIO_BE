<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\StockInRepository;
use App\Models\StockIn;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentStockInRepository implements StockInRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $search = trim((string) ($filters['q'] ?? ''));

        return StockIn::query()
            ->with('supplier', 'purchaseOrder', 'lines.product', 'lines.stockItems', 'lines.returnToSupplierLines.returnToSupplier')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('stock_in_number', 'like', '%'.$search.'%')
                        ->orWhereHas('supplier', function (Builder $supplierQuery) use ($search): void {
                            $supplierQuery->where('supplier_code', 'like', '%'.$search.'%')
                                ->orWhere('supplier_name', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('purchaseOrder', function (Builder $purchaseOrderQuery) use ($search): void {
                            $purchaseOrderQuery->where('po_number', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('lines.product', function (Builder $productQuery) use ($search): void {
                            $productQuery->where('product_code', 'like', '%'.$search.'%')
                                ->orWhere('product_name', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('lines.stockItems', function (Builder $stockItemQuery) use ($search): void {
                            $stockItemQuery->where('serial_number', 'like', '%'.$search.'%');
                        });
                });
            })
            ->latest('id')
            ->paginate($perPage);
    }

    public function findOrFail(int $id): StockIn
    {
        return StockIn::query()
            ->with('supplier', 'lines.product', 'lines.stockItems', 'lines.returnToSupplierLines.returnToSupplier')
            ->findOrFail($id);
    }
}
