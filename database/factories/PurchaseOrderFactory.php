<?php

namespace Database\Factories;

use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'po_number' => strtoupper(fake()->bothify('PO-######')),
            'po_date' => fake()->date(),
            'supplier_id' => Supplier::factory(),
            'expected_delivery_date' => fake()->date(),
            'status' => PurchaseOrderStatus::Draft->value,
            'created_by' => User::factory(),
            'remarks' => fake()->optional()->sentence(),
        ];
    }
}
