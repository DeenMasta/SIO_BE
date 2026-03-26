<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\ReturnToSupplierRepository;
use App\Models\ReturnToSupplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentReturnToSupplierRepository implements ReturnToSupplierRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return ReturnToSupplier::query()->with('lines')->latest('id')->paginate($perPage);
    }

    public function findOrFail(int $id): ReturnToSupplier
    {
        return ReturnToSupplier::query()->with('lines')->findOrFail($id);
    }

    public function create(array $data): ReturnToSupplier
    {
        return ReturnToSupplier::query()->create($data);
    }
}
