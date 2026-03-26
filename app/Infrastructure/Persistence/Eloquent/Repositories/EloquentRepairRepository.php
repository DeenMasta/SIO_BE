<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\RepairRepository;
use App\Models\Repair;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentRepairRepository implements RepairRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Repair::query()->latest('id')->paginate($perPage);
    }

    public function findOrFail(int $id): Repair
    {
        return Repair::query()->findOrFail($id);
    }

    public function create(array $data): Repair
    {
        return Repair::query()->create($data);
    }

    public function update(Repair $repair, array $data): Repair
    {
        $repair->fill($data)->save();

        return $repair->refresh();
    }
}
