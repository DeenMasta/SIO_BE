<?php

namespace App\Application\MasterData\Suppliers\UseCases;

use App\Application\Contracts\Repositories\SupplierRepository;
use App\Application\Contracts\UseCase;
use App\Models\Supplier;

class UpdateSupplierUseCase implements UseCase
{
    public function __construct(private readonly SupplierRepository $suppliers)
    {
    }

    public function execute(mixed $payload = null): Supplier
    {
        $data = (array) $payload;

        /** @var Supplier $supplier */
        $supplier = $data['supplier'];

        unset($data['supplier']);

        return $this->suppliers->update($supplier, $data);
    }
}
