<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InternalStockMovementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_serialized_item_can_move_to_showroom_and_return_to_stock(): void
    {
        $admin = User::factory()->admin()->create();
        [, $device, $stockItemId, $stockInId, $stockInLineId] = $this->createReceivedDeviceItem($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/qc-transactions', [
            'qc_reference_number' => 'QC-INT-100001',
            'stock_in_id' => $stockInId,
            'qc_date' => now()->toDateString(),
            'lines' => [[
                'stock_in_line_id' => $stockInLineId,
                'product_id' => $device->id,
                'qc_result' => 'PASS',
                'stock_item_ids' => [$stockItemId],
            ]],
        ])->assertCreated();

        $issue = $this->postJson('/api/internal-stock-movements', [
            'movement_number' => 'INT-OUT-100001',
            'movement_date' => now()->toDateString(),
            'purpose' => 'SHOWROOM',
            'lines' => [[
                'product_id' => $device->id,
                'qty' => 1,
                'stock_item_ids' => [$stockItemId],
            ]],
        ])->assertCreated();

        $movementId = (int) $issue->json('data.id');

        $this->assertDatabaseHas('stock_items', [
            'id' => $stockItemId,
            'current_status' => 'INTERNAL_USE',
            'is_available' => 0,
        ]);

        $this->getJson('/api/inventories')
            ->assertOk()
            ->assertJsonPath('data.0.qty_internal_use', 1);

        $this->patchJson('/api/internal-stock-movements/'.$movementId.'/return', [
            'movement_number' => 'INT-RET-100001',
            'movement_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $device->id,
                'qty' => 1,
                'stock_item_ids' => [$stockItemId],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('stock_items', [
            'id' => $stockItemId,
            'current_status' => 'IN_STOCK',
            'is_available' => 1,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $device->id,
            'movement_type' => 'INTERNAL_USE_OUT',
            'stock_item_id' => $stockItemId,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $device->id,
            'movement_type' => 'INTERNAL_USE_RETURN',
            'stock_item_id' => $stockItemId,
        ]);
    }

    public function test_non_serialized_item_can_move_to_internal_use_and_return_partial_qty(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'product_code' => 'ACC-SHOW-001',
            'product_type' => 'ACCESSORY',
            'requires_serial_number' => false,
        ]);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-INT-ACC-001',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'received_qty' => 5,
            ]],
        ])->assertCreated();

        $issue = $this->postJson('/api/internal-stock-movements', [
            'movement_number' => 'INT-OUT-ACC-001',
            'movement_date' => now()->toDateString(),
            'purpose' => 'INTERNAL_USE',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 3,
            ]],
        ])->assertCreated();

        $movementId = (int) $issue->json('data.id');

        $this->patchJson('/api/internal-stock-movements/'.$movementId.'/return', [
            'movement_number' => 'INT-RET-ACC-001',
            'movement_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 2,
            ]],
        ])->assertOk();

        $inventory = $this->getJson('/api/inventories')
            ->assertOk()
            ->json('data.0');

        $this->assertSame(4, $inventory['qty_available']);
        $this->assertSame(1, $inventory['qty_internal_use']);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'stock_item_id' => null,
            'movement_type' => 'INTERNAL_USE_OUT',
            'qty_out' => 3,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'stock_item_id' => null,
            'movement_type' => 'INTERNAL_USE_RETURN',
            'qty_in' => 2,
        ]);
    }

    public function test_cannot_return_more_non_serialized_qty_than_issued(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'product_code' => 'ACC-SHOW-002',
            'product_type' => 'ACCESSORY',
            'requires_serial_number' => false,
        ]);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-INT-ACC-002',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'received_qty' => 2,
            ]],
        ])->assertCreated();

        $issue = $this->postJson('/api/internal-stock-movements', [
            'movement_number' => 'INT-OUT-ACC-002',
            'movement_date' => now()->toDateString(),
            'purpose' => 'SHOWROOM',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 1,
            ]],
        ])->assertCreated();

        $movementId = (int) $issue->json('data.id');

        $this->patchJson('/api/internal-stock-movements/'.$movementId.'/return', [
            'movement_number' => 'INT-RET-ACC-002',
            'movement_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 2,
            ]],
        ])->assertUnprocessable();
    }

    /**
     * @return array{Supplier, Product, int, int, int}
     */
    private function createReceivedDeviceItem(User $admin): array
    {
        Sanctum::actingAs($admin, ['admin-access']);

        $supplier = Supplier::factory()->create();
        $device = Product::factory()->create([
            'product_code' => 'DEV-INT-1001',
            'product_type' => 'DEVICE',
        ]);

        $response = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-INT-100001-'.fake()->numerify('##'),
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $device->id,
                'received_qty' => 1,
                'serial_numbers' => ['DEV-INT-SN-'.fake()->numerify('####')],
            ]],
        ])->assertCreated();

        return [
            $supplier,
            $device,
            (int) $response->json('data.lines.0.stock_items.0.id'),
            (int) $response->json('data.id'),
            (int) $response->json('data.lines.0.id'),
        ];
    }
}
