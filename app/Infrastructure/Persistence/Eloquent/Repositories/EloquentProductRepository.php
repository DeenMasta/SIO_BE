<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\ProductRepository;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentProductRepository implements ProductRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $search = trim((string) ($filters['q'] ?? ''));
        $supplierId = $filters['supplier_id'] ?? null;

        return Product::query()
            ->with('supplier', 'accessories', 'conditions')
            ->when($supplierId !== null && $supplierId !== '', function (Builder $query) use ($supplierId): void {
                $query->where('supplier_id', $supplierId);
            })
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $productQuery) use ($search): void {
                    $productQuery->where('product_code', 'like', '%'.$search.'%')
                        ->orWhere('product_name', 'like', '%'.$search.'%')
                        ->orWhere('product_model', 'like', '%'.$search.'%')
                        ->orWhere('uom', 'like', '%'.$search.'%')
                        ->orWhere('remarks', 'like', '%'.$search.'%')
                        ->orWhereHas('supplier', function (Builder $supplierQuery) use ($search): void {
                            $supplierQuery->where('supplier_code', 'like', '%'.$search.'%')
                                ->orWhere('supplier_name', 'like', '%'.$search.'%');
                        });
                });
            })
            ->latest('id')
            ->paginate($perPage);
    }

    public function findOrFail(int $id): Product
    {
        return Product::query()->with('supplier', 'accessories', 'conditions')->findOrFail($id);
    }

    public function create(array $data): Product
    {
        return Product::query()->create($data)->load('supplier', 'accessories', 'conditions');
    }

    public function update(Product $product, array $data): Product
    {
        $product->fill($data)->save();

        return $product->refresh()->load('supplier', 'accessories', 'conditions');
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }
}
