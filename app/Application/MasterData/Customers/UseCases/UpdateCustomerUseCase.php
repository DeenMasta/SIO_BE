<?php

namespace App\Application\MasterData\Customers\UseCases;

use App\Application\Contracts\Repositories\CustomerRepository;
use App\Application\Contracts\UseCase;
use App\Models\Customer;

class UpdateCustomerUseCase implements UseCase
{
    public function __construct(private readonly CustomerRepository $customers)
    {
    }

    public function execute(mixed $payload = null): Customer
    {
        $data = (array) $payload;

        /** @var Customer $customer */
        $customer = $data['customer'];

        unset($data['customer']);

        return $this->customers->update($customer, $data);
    }
}
