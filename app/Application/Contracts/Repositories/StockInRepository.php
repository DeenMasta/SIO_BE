<?php

namespace App\Application\Contracts\Repositories;

use App\Models\StockIn;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface StockInRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator;

    public function findOrFail(int $id): StockIn;
}
