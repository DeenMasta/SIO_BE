<?php

namespace App\Application\MasterData\Products\UseCases;

use App\Application\Contracts\Repositories\ProductRepository;
use App\Application\Contracts\UseCase;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

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

        $hasAccessories = array_key_exists('accessories', $data);
        $accessories = (array) ($data['accessories'] ?? []);
        unset($data['accessories']);

        $hasConditions = array_key_exists('conditions', $data);
        $conditions = (array) ($data['conditions'] ?? []);
        unset($data['conditions']);

        /** @var Product */
        return DB::transaction(function () use ($product, $data, $hasAccessories, $accessories, $hasConditions, $conditions): Product {
            $updated = $this->products->update($product, $data);

            if ($hasAccessories) {
                $updated->accessories()->delete();

                if ($accessories !== []) {
                    $updated->accessories()->createMany(array_map(
                        static fn (array $item): array => [
                            'accessory_name' => (string) $item['accessory_name'],
                            'quantity' => (int) ($item['quantity'] ?? 1),
                            'remarks' => $item['remarks'] ?? null,
                        ],
                        $accessories,
                    ));
                }
            }

            if ($hasConditions) {
                $updated->conditions()->delete();

                if ($conditions !== []) {
                    $updated->conditions()->createMany(array_map(
                        static fn (array $item): array => [
                            'condition_name' => (string) $item['condition_name'],
                        ],
                        $conditions,
                    ));
                }
            }

            return $updated->load('supplier', 'accessories', 'conditions');
        });
    }
}
