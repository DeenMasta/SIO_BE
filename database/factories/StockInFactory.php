<?php

namespace Database\Factories;

use App\Domain\PurchasingInbound\Enums\StockInStatus;
use App\Models\StockIn;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockIn>
 */
class StockInFactory extends Factory
{
    protected $model = StockIn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_in_number' => strtoupper(fake()->bothify('SIN-######')),
            'stock_in_date' => fake()->date(),
            'purchase_order_id' => null,
            'supplier_id' => Supplier::factory(),
            'stock_in_pic_id' => User::factory(),
            'qc_person_id' => null,
            'status' => StockInStatus::Posted->value,
            'remarks' => fake()->optional()->sentence(),
        ];
    }
}
