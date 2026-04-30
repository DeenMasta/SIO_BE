<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\ReturnToSupplierRepository;
use App\Models\ReturnToSupplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentReturnToSupplierRepository implements ReturnToSupplierRepository
{
    private const DETAIL_RELATIONS = [
        'supplier',
        'stockIn',
        'lines.product',
        'lines.stockItem.product',
        'lines.stockInLine.product',
    ];

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return ReturnToSupplier::query()
            ->with(self::DETAIL_RELATIONS)
            ->latest('id')
            ->paginate($perPage);
    }

    public function findOrFail(int $id): ReturnToSupplier
    {
        return ReturnToSupplier::query()
            ->with(self::DETAIL_RELATIONS)
            ->findOrFail($id);
    }

    public function create(array $data): ReturnToSupplier
    {
        return ReturnToSupplier::query()->create($data);
    }
}
