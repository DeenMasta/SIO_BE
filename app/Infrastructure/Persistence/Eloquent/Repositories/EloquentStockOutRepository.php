<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\StockOutRepository;
use App\Models\StockOut;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentStockOutRepository implements StockOutRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return StockOut::query()->with('lines.lineItems')->latest('id')->paginate($perPage);
    }

    public function findOrFail(int $id): StockOut
    {
        return StockOut::query()->with('lines.lineItems')->findOrFail($id);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?StockOut
    {
        return StockOut::query()->with('lines.lineItems')->where('idempotency_key', $idempotencyKey)->first();
    }

    public function create(array $data): StockOut
    {
        return StockOut::query()->create($data);
    }
}
