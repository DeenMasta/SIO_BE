<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\ProductRepository;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentProductRepository implements ProductRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Product::query()->with('supplier', 'accessories', 'conditions')->latest('id')->paginate($perPage);
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
