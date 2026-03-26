<?php

namespace App\Application\Contracts\Repositories;

use App\Models\Repair;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface RepairRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator;

    public function findOrFail(int $id): Repair;

    public function create(array $data): Repair;

    public function update(Repair $repair, array $data): Repair;
}
