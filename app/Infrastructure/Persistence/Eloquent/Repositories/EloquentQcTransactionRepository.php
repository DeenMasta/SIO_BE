<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\QcTransactionRepository;
use App\Models\QcTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentQcTransactionRepository implements QcTransactionRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return QcTransaction::query()->with('lines')->latest('id')->paginate($perPage);
    }

    public function findOrFail(int $id): QcTransaction
    {
        return QcTransaction::query()->with('lines')->findOrFail($id);
    }

    public function create(array $data): QcTransaction
    {
        return QcTransaction::query()->create($data);
    }
}
