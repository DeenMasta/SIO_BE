<?php

namespace Tests\Feature;

use App\Domain\InventoryCore\Enums\SerialSource;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\QuickStockOut;
use App\Models\StockIn;
use App\Models\StockInLine;
use App\Models\StockItem;
use App\Models\StockOut;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuickStockOutApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection()->getPdo()->sqliteCreateFunction('GREATEST', static fn (...$values): int|float => max($values), -1);
    }

    public function test_staff_can_create_a_customer_from_the_quick_stock_out_flow(): void
    {
        $staff = User::factory()->staff()->create();
        Sanctum::actingAs($staff, ['staff-access']);

        $this->postJson('/api/quick-stock-outs/customers', [
            'customer_name' => 'Quick Stock Out Customer',
            'contact_person' => 'Siti',
            'phone' => '0123456789',
            'email' => 'quick-stock-out@example.com',
            'address' => 'Lot 10',
            'status' => 'ACTIVE',
        ])
            ->assertCreated()
            ->assertJsonPath('data.customer_name', 'Quick Stock Out Customer');

        $this->assertDatabaseHas('customers', [
            'customer_name' => 'Quick Stock Out Customer',
        ]);

        $this->postJson('/api/customers', [
            'customer_name' => 'Master Data Customer',
            'status' => 'ACTIVE',
        ])->assertForbidden();
    }

    public function test_staff_can_post_quick_stock_out_by_scanned_serial_number(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        [$stockItemId, $productId] = $this->seedInStockSerializedItem($staff);
        $serialNumber = (string) StockItem::query()->findOrFail($stockItemId)->serial_number;

        Sanctum::actingAs($staff, ['admin-access']);

        $response = $this->postJson('/api/quick-stock-outs', [
            'customer_id' => $customer->id,
            'qso_date' => now()->toDateString(),
            'remarks' => 'Quick dispatch from scan',
            'serial_numbers' => [$serialNumber],
        ])->assertCreated();

        $quickStockOutId = (int) $response->json('data.id');
        $stockOutId = (int) $response->json('data.stock_out_id');

        $response
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.lines.0.product_id', $productId)
            ->assertJsonPath('data.lines.0.quantity', 1)
            ->assertJsonPath('data.lines.0.serials.0.serial_number', $serialNumber);

        $this->assertDatabaseHas('quick_stock_outs', [
            'id' => $quickStockOutId,
            'stock_out_id' => $stockOutId,
            'status' => 'PENDING',
        ]);

        $this->assertDatabaseHas('stock_out', [
            'id' => $stockOutId,
            'customer_id' => $customer->id,
            'quick_stock_out_id' => $quickStockOutId,
        ]);

        $this->assertDatabaseHas('stock_out_lines', [
            'stock_out_id' => $stockOutId,
            'product_id' => $productId,
            'quick_stock_out_line_id' => (int) $response->json('data.lines.0.id'),
        ]);

        $this->assertDatabaseHas('stock_items', [
            'id' => $stockItemId,
            'current_status' => 'DELIVERED',
            'is_available' => 0,
        ]);
    }

    public function test_quick_stock_out_rejects_duplicate_serials_in_same_request(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        [$stockItemId] = $this->seedInStockSerializedItem($staff);
        $serialNumber = (string) StockItem::query()->findOrFail($stockItemId)->serial_number;

        Sanctum::actingAs($staff, ['admin-access']);

        $this->postJson('/api/quick-stock-outs', [
            'customer_id' => $customer->id,
            'qso_date' => now()->toDateString(),
            'serial_numbers' => [$serialNumber, $serialNumber],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_numbers']);
    }

    public function test_quick_stock_out_can_be_converted_into_fulfilled_sale_order_without_second_deduction(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        [$stockItemId, $productId] = $this->seedInStockSerializedItem($staff);
        $serialNumber = (string) StockItem::query()->findOrFail($stockItemId)->serial_number;

        Sanctum::actingAs($staff, ['admin-access']);

        $created = $this->postJson('/api/quick-stock-outs', [
            'customer_id' => $customer->id,
            'qso_date' => now()->toDateString(),
            'serial_numbers' => [$serialNumber],
        ])->assertCreated();

        $quickStockOutId = (int) $created->json('data.id');
        $stockOutId = (int) $created->json('data.stock_out_id');

        $this->postJson('/api/quick-stock-outs/'.$quickStockOutId.'/convert', [
            'invoice_number' => 'INV-QSO-0001',
            'remarks' => 'Converted after dispatch',
        ])->assertOk();

        $quickStockOut = QuickStockOut::query()->findOrFail($quickStockOutId);
        $stockOut = StockOut::query()->with('lines')->findOrFail($stockOutId);

        $this->assertSame('CONVERTED', $quickStockOut->status->value);
        $this->assertNotNull($quickStockOut->converted_sale_order_id);
        $this->assertSame($quickStockOut->converted_sale_order_id, $stockOut->sale_order_id);

        $this->assertDatabaseHas('sale_orders', [
            'id' => $quickStockOut->converted_sale_order_id,
            'quick_stock_out_id' => $quickStockOutId,
            'customer_id' => $customer->id,
            'status' => 'FULFILLED',
            'invoice_number' => 'INV-QSO-0001',
        ]);

        $this->assertDatabaseHas('sale_order_lines', [
            'sale_order_id' => $quickStockOut->converted_sale_order_id,
            'product_id' => $productId,
            'ordered_qty' => 1,
            'fulfilled_qty' => 1,
        ]);

        $this->assertNotNull($stockOut->lines->first()?->sale_order_line_id);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_staff_can_post_quick_stock_out_for_non_serial_item(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        [$productId] = $this->seedInStockNonSerializedItem($staff, 5);

        Sanctum::actingAs($staff, ['admin-access']);

        $response = $this->postJson('/api/quick-stock-outs', [
            'customer_id' => $customer->id,
            'qso_date' => now()->toDateString(),
            'remarks' => 'Quick dispatch bulk item',
            'lines' => [[
                'product_id' => $productId,
                'qty' => 3,
                'remarks' => 'Bulk issue',
            ]],
        ])->assertCreated();

        $quickStockOutId = (int) $response->json('data.id');
        $stockOutId = (int) $response->json('data.stock_out_id');

        $response
            ->assertJsonPath('data.lines.0.product_id', $productId)
            ->assertJsonPath('data.lines.0.quantity', 3);

        $this->assertDatabaseHas('quick_stock_out_lines', [
            'quick_stock_out_id' => $quickStockOutId,
            'product_id' => $productId,
            'quantity' => 3,
        ]);

        $this->assertDatabaseMissing('quick_stock_out_line_items', [
            'quick_stock_out_line_id' => (int) $response->json('data.lines.0.id'),
        ]);

        $this->assertDatabaseHas('stock_out_lines', [
            'stock_out_id' => $stockOutId,
            'product_id' => $productId,
            'qty' => 3,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $productId,
            'stock_item_id' => null,
            'movement_type' => 'STOCK_OUT',
            'qty_out' => 3,
            'from_status' => 'IN_STOCK',
            'to_status' => 'DELIVERED',
        ]);
    }

    public function test_staff_can_post_mixed_quick_stock_out_for_serial_and_non_serial_items(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        [$stockItemId, $serializedProductId] = $this->seedInStockSerializedItem($staff);
        [$nonSerializedProductId] = $this->seedInStockNonSerializedItem($staff, 4);
        $serialNumber = (string) StockItem::query()->findOrFail($stockItemId)->serial_number;

        Sanctum::actingAs($staff, ['admin-access']);

        $response = $this->postJson('/api/quick-stock-outs', [
            'customer_id' => $customer->id,
            'qso_date' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $serializedProductId,
                    'qty' => 1,
                    'serial_numbers' => [$serialNumber],
                ],
                [
                    'product_id' => $nonSerializedProductId,
                    'qty' => 2,
                    'remarks' => 'Bulk item',
                ],
            ],
        ])->assertCreated();

        $this->assertCount(2, $response->json('data.lines'));

        $this->assertDatabaseHas('stock_out_lines', [
            'stock_out_id' => (int) $response->json('data.stock_out_id'),
            'product_id' => $serializedProductId,
            'qty' => 1,
        ]);

        $this->assertDatabaseHas('stock_out_lines', [
            'stock_out_id' => (int) $response->json('data.stock_out_id'),
            'product_id' => $nonSerializedProductId,
            'qty' => 2,
        ]);
    }

    /**
     * @return array{int, int}
     */
    private function seedInStockSerializedItem(User $user): array
    {
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'product_type' => 'DEVICE',
            'requires_serial_number' => true,
            'created_by' => $user->id,
        ]);

        $stockIn = StockIn::query()->create([
            'stock_in_number' => 'SIN-QSO-'.fake()->numerify('######'),
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'stock_in_pic_id' => $user->id,
            'status' => 'RECEIVED',
        ]);

        $stockInLine = StockInLine::query()->create([
            'stock_in_id' => $stockIn->id,
            'product_id' => $product->id,
            'received_qty' => 1,
        ]);

        $stockItem = StockItem::query()->create([
            'product_id' => $product->id,
            'stock_in_line_id' => $stockInLine->id,
            'serial_number' => 'QSO-SN-'.fake()->numerify('######'),
            'serial_source' => SerialSource::Generated,
            'current_status' => StockItemStatus::InStock,
            'received_condition' => 'GOOD',
            'qc_status' => StockItemQcStatus::Passed,
            'is_available' => true,
            'last_movement_at' => now(),
        ]);

        return [(int) $stockItem->id, (int) $product->id];
    }

    /**
     * @return array{int}
     */
    private function seedInStockNonSerializedItem(User $user, int $qty): array
    {
        $product = Product::factory()->create([
            'product_type' => 'CONSUMABLE',
            'requires_serial_number' => false,
            'created_by' => $user->id,
        ]);

        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $product->id,
            'stock_item_id' => null,
            'movement_type' => MovementType::Adjustment,
            'reference_table' => 'products',
            'reference_id' => $product->id,
            'qty_in' => $qty,
            'qty_out' => 0,
            'from_status' => null,
            'to_status' => StockItemStatus::InStock->value,
            'performed_by' => $user->id,
            'remarks' => 'Seed non-serialized stock',
        ]);

        return [(int) $product->id];
    }
}
