<?php

namespace App\Application\MasterData\Customers\UseCases;

use App\Application\Contracts\Repositories\CustomerRepository;
use App\Application\Contracts\UseCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListCustomersUseCase implements UseCase
{
    public function __construct(private readonly CustomerRepository $customers)
    {
    }

    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $perPage = is_array($payload) ? (int) ($payload['per_page'] ?? 15) : 15;

        return $this->customers->paginate($perPage > 0 ? $perPage : 15);
    }
}
