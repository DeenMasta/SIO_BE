<?php

namespace App\Application\MasterData\Customers\UseCases;

use App\Application\Contracts\Repositories\CustomerRepository;
use App\Application\Contracts\UseCase;
use App\Models\Customer;

class DeleteCustomerUseCase implements UseCase
{
    public function __construct(private readonly CustomerRepository $customers)
    {
    }

    public function execute(mixed $payload = null): null
    {
        /** @var Customer $customer */
        $customer = $payload;

        $this->customers->delete($customer);

        return null;
    }
}
