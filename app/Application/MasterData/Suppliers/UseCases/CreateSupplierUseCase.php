<?php

namespace App\Application\MasterData\Suppliers\UseCases;

use App\Application\Contracts\Repositories\SupplierRepository;
use App\Application\Contracts\UseCase;
use App\Models\Supplier;

class CreateSupplierUseCase implements UseCase
{
    public function __construct(private readonly SupplierRepository $suppliers)
    {
    }

    public function execute(mixed $payload = null): Supplier
    {
        return $this->suppliers->create((array) $payload);
    }
}
