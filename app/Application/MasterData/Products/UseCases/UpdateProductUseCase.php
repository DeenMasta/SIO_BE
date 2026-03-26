<?php

namespace App\Application\MasterData\Products\UseCases;

use App\Application\Contracts\Repositories\ProductRepository;
use App\Application\Contracts\UseCase;
use App\Models\Product;

class UpdateProductUseCase implements UseCase
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function execute(mixed $payload = null): Product
    {
        $data = (array) $payload;

        /** @var Product $product */
        $product = $data['product'];

        unset($data['product']);

        return $this->products->update($product, $data);
    }
}
