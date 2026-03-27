<?php

namespace App\Application\MasterData\Products\UseCases;

use App\Application\Contracts\Repositories\ProductRepository;
use App\Application\Contracts\UseCase;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class CreateProductUseCase implements UseCase
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function execute(mixed $payload = null): Product
    {
        $data = (array) $payload;
        $accessories = (array) ($data['accessories'] ?? []);
        $conditions = (array) ($data['conditions'] ?? []);

        unset($data['accessories']);
        unset($data['conditions']);

        /** @var Product */
        return DB::transaction(function () use ($data, $accessories, $conditions): Product {
            $product = $this->products->create($data);

            if ($accessories !== []) {
                $product->accessories()->createMany(array_map(
                    static fn (array $item): array => [
                        'accessory_name' => (string) $item['accessory_name'],
                        'quantity' => (int) ($item['quantity'] ?? 1),
                        'remarks' => $item['remarks'] ?? null,
                    ],
                    $accessories,
                ));
            }

            if ($conditions !== []) {
                $product->conditions()->createMany(array_map(
                    static fn (array $item): array => [
                        'condition_name' => (string) $item['condition_name'],
                    ],
                    $conditions,
                ));
            }

            return $product->load('supplier', 'accessories', 'conditions');
        });
    }
}
