<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\CustomerReturnRepository;
use App\Models\CustomerReturn;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentCustomerReturnRepository implements CustomerReturnRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return CustomerReturn::query()->with('lines')->latest('id')->paginate($perPage);
    }

    public function findOrFail(int $id): CustomerReturn
    {
        return CustomerReturn::query()->with('lines')->findOrFail($id);
    }

    public function create(array $data): CustomerReturn
    {
        return CustomerReturn::query()->create($data);
    }
}
