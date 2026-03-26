<?php

namespace App\Application\Contracts\Repositories;

use App\Models\StockOut;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface StockOutRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator;

    public function findOrFail(int $id): StockOut;

    public function findByIdempotencyKey(string $idempotencyKey): ?StockOut;

    public function create(array $data): StockOut;
}
