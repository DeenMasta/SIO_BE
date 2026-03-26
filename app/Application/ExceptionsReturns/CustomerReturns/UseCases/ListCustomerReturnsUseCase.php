<?php

namespace App\Application\ExceptionsReturns\CustomerReturns\UseCases;

use App\Application\Contracts\Repositories\CustomerReturnRepository;
use App\Application\Contracts\UseCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListCustomerReturnsUseCase implements UseCase
{
    public function __construct(private readonly CustomerReturnRepository $returns)
    {
    }

    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $perPage = is_array($payload) ? (int) ($payload['per_page'] ?? 15) : 15;

        return $this->returns->paginate($perPage > 0 ? $perPage : 15);
    }
}
