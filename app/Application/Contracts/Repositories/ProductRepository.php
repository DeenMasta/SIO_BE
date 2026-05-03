<?php

namespace App\Application\Contracts\Repositories;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProductRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    public function findOrFail(int $id): Product;

    public function create(array $data): Product;

    public function update(Product $product, array $data): Product;

    public function delete(Product $product): void;
}
