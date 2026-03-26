<?php

namespace App\Application\MasterData\Products\UseCases;

use App\Application\Contracts\Repositories\ProductRepository;
use App\Application\Contracts\UseCase;
use App\Models\Product;

class DeleteProductUseCase implements UseCase
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function execute(mixed $payload = null): null
    {
        /** @var Product $product */
        $product = $payload;

        $this->products->delete($product);

        return null;
    }
}
