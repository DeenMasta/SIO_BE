<?php

namespace App\Application\ExceptionsReturns\Repairs\UseCases;

use App\Application\Contracts\Repositories\RepairRepository;
use App\Application\Contracts\UseCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListRepairsUseCase implements UseCase
{
    public function __construct(private readonly RepairRepository $repairs)
    {
    }

    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $perPage = is_array($payload) ? (int) ($payload['per_page'] ?? 15) : 15;

        return $this->repairs->paginate($perPage > 0 ? $perPage : 15);
    }
}
