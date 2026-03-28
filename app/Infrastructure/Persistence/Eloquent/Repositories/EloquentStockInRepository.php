<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\StockInRepository;
use App\Models\StockIn;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentStockInRepository implements StockInRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return StockIn::query()->with('lines.product', 'lines.stockItems')->latest('id')->paginate($perPage);
    }

    public function findOrFail(int $id): StockIn
    {
        return StockIn::query()->with('lines.product', 'lines.stockItems')->findOrFail($id);
    }
}
