<?php

namespace App\Application\Contracts\Repositories;

use App\Models\CustomerReturn;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CustomerReturnRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator;

    public function findOrFail(int $id): CustomerReturn;

    public function create(array $data): CustomerReturn;
}
