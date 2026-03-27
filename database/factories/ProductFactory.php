<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_code' => strtoupper(fake()->bothify('PRD-####')),
            'product_name' => fake()->words(3, true),
            'product_type' => fake()->randomElement(['DEVICE', 'ACCESSORY', 'CONSUMABLE']),
            'supplier_id' => Supplier::factory(),
            'selling_price' => fake()->randomFloat(2, 10, 1000),
            'uom' => fake()->randomElement(['PCS', 'BOX']),
            'reorder_level' => fake()->numberBetween(0, 100),
            'remarks' => fake()->optional()->sentence(),
            'status' => fake()->randomElement(['ACTIVE', 'INACTIVE']),
            'created_by' => User::factory(),
        ];
    }
}
