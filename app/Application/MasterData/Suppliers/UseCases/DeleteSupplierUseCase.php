<?php

namespace App\Application\MasterData\Suppliers\UseCases;

use App\Application\Contracts\Repositories\SupplierRepository;
use App\Application\Contracts\UseCase;
use App\Models\Supplier;

class DeleteSupplierUseCase implements UseCase
{
    public function __construct(private readonly SupplierRepository $suppliers)
    {
    }

    public function execute(mixed $payload = null): null
    {
        /** @var Supplier $supplier */
        $supplier = $payload;

        $this->suppliers->delete($supplier);

        return null;
    }
}
