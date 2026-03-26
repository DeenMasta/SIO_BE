<?php

namespace App\Application\MasterData\Customers\UseCases;

use App\Application\Contracts\Repositories\CustomerRepository;
use App\Application\Contracts\UseCase;
use App\Models\Customer;

class CreateCustomerUseCase implements UseCase
{
    public function __construct(private readonly CustomerRepository $customers)
    {
    }

    public function execute(mixed $payload = null): Customer
    {
        return $this->customers->create((array) $payload);
    }
}
