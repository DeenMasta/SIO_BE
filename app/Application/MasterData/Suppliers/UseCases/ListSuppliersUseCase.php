<?php

namespace App\Application\MasterData\Suppliers\UseCases;

use App\Application\Contracts\Repositories\SupplierRepository;
use App\Application\Contracts\UseCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListSuppliersUseCase implements UseCase
{
    public function __construct(private readonly SupplierRepository $suppliers)
    {
    }

    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $perPage = is_array($payload) ? (int) ($payload['per_page'] ?? 15) : 15;

        return $this->suppliers->paginate($perPage > 0 ? $perPage : 15);
    }
}
