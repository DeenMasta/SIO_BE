<?php

namespace App\Application\Contracts\Repositories;

use App\Models\QcTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface QcTransactionRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator;

    public function findOrFail(int $id): QcTransaction;

    public function create(array $data): QcTransaction;
}
