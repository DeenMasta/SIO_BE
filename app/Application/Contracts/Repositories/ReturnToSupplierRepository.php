<?php

namespace App\Application\Contracts\Repositories;

use App\Models\ReturnToSupplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ReturnToSupplierRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator;

    public function findOrFail(int $id): ReturnToSupplier;

    public function create(array $data): ReturnToSupplier;
}
