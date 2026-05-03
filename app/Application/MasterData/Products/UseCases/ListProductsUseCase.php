<?php

namespace App\Application\MasterData\Products\UseCases;

use App\Application\Contracts\Repositories\ProductRepository;
use App\Application\Contracts\UseCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListProductsUseCase implements UseCase
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $perPage = is_array($payload) ? (int) ($payload['per_page'] ?? 15) : 15;
        $filters = is_array($payload) ? $payload : [];

        return $this->products->paginate($perPage > 0 ? $perPage : 15, $filters);
    }
}
