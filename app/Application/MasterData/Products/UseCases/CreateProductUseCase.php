<?php

namespace App\Application\MasterData\Products\UseCases;

use App\Application\Contracts\Repositories\ProductRepository;
use App\Application\Contracts\UseCase;
use App\Models\Product;

class CreateProductUseCase implements UseCase
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function execute(mixed $payload = null): Product
    {
        return $this->products->create((array) $payload);
    }
}
