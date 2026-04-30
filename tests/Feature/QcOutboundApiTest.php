<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QcOutboundApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_post_qc_pass_and_move_item_to_in_stock(): void
    {
        $admin = User::factory()->admin()->create();
        [$supplier, $device, $stockItemId, $stockInId, $stockInLineId] = $this->createReceivedDeviceItem($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/qc-transactions', [
            'qc_reference_number' => 'QC-100001',
            'stock_in_id' => $stockInId,
            'qc_date' => now()->toDateString(),
            'lines' => [
                [
                    'stock_in_line_id' => $stockInLineId,
                    'product_id' => $device->id,
                    'qc_result' => 'PASS',
                    'stock_item_ids' => [$stockItemId],
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('stock_items', [
            'id' => $stockItemId,
            'current_status' => 'IN_STOCK',
            'is_available' => 1,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $device->id,
            'movement_type' => 'QC_PASS',
            'stock_item_id' => $stockItemId,
        ]);

        $this->assertTrue($supplier->exists);
    }

    public function test_qc_rejects_invalid_status_transition_for_item_not_received(): void
    {
        $admin = User::factory()->admin()->create();
        [, $device, $stockItemId, $stockInId, $stockInLineId] = $this->createReceivedDeviceItem($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/qc-transactions', [
            'qc_reference_number' => 'QC-100002',
            'stock_in_id' => $stockInId,
            'qc_date' => now()->toDateString(),
            'lines' => [
                [
                    'stock_in_line_id' => $stockInLineId,
                    'product_id' => $device->id,
                    'qc_result' => 'PASS',
                    'stock_item_ids' => [$stockItemId],
                ],
            ],
        ])->assertCreated();

        $this->postJson('/api/qc-transactions', [
            'qc_reference_number' => 'QC-100003',
            'stock_in_id' => $stockInId,
            'qc_date' => now()->toDateString(),
            'lines' => [
                [
                    'stock_in_line_id' => $stockInLineId,
                    'product_id' => $device->id,
                    'qc_result' => 'FAIL',
                    'stock_item_ids' => [$stockItemId],
                ],
            ],
        ])->assertUnprocessable();
    }

    public function test_admin_can_post_stock_out_idempotently(): void
    {
        $admin = User::factory()->admin()->create();
        [$supplier, $device, $stockItemId, $stockInId, $stockInLineId] = $this->createReceivedDeviceItem($admin);
        $customer = Customer::factory()->create();

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/qc-transactions', [
            'qc_reference_number' => 'QC-100004',
            'stock_in_id' => $stockInId,
            'qc_date' => now()->toDateString(),
            'lines' => [
                [
                    'stock_in_line_id' => $stockInLineId,
                    'product_id' => $device->id,
                    'qc_result' => 'PASS',
                    'stock_item_ids' => [$stockItemId],
                ],
            ],
        ])->assertCreated();

        $payload = [
            'stock_out_number' => 'SOUT-100001',
            'idempotency_key' => 'idem-flow4-100001',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-100001',
            'packing_verified' => true,
            'lines' => [
                [
                    'product_id' => $device->id,
                    'qty' => 1,
                    'stock_item_ids' => [$stockItemId],
                ],
            ],
        ];

        $first = $this->postJson('/api/stock-outs', $payload)->assertCreated();
        $firstId = (int) $first->json('data.id');

        $second = $this->postJson('/api/stock-outs', $payload)->assertOk();
        $secondId = (int) $second->json('data.id');

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, \App\Models\StockOut::query()->count());

        $this->assertDatabaseHas('stock_items', [
            'id' => $stockItemId,
            'current_status' => 'DELIVERED',
            'is_available' => 0,
        ]);

        $this->assertSame(3, StockMovement::query()->count());
        $this->assertTrue($supplier->exists);
    }

    public function test_stock_out_rejects_items_not_in_stock(): void
    {
        $admin = User::factory()->admin()->create();
        [, $device, $stockItemId] = $this->createReceivedDeviceItem($admin);
        $customer = Customer::factory()->create();

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/stock-outs', [
            'stock_out_number' => 'SOUT-100002',
            'idempotency_key' => 'idem-flow4-100002',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-100002',
            'lines' => [
                [
                    'product_id' => $device->id,
                    'qty' => 1,
                    'stock_item_ids' => [$stockItemId],
                ],
            ],
        ])->assertUnprocessable();
    }

    public function test_staff_can_post_qc_and_stock_out(): void
    {
        $admin = User::factory()->admin()->create();
        [, $device, $stockItemId, $stockInId, $stockInLineId] = $this->createReceivedDeviceItem($admin);
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();

        Sanctum::actingAs($staff, ['staff-access']);

        $this->postJson('/api/qc-transactions', [
            'qc_reference_number' => 'QC-STF-100001',
            'stock_in_id' => $stockInId,
            'qc_date' => now()->toDateString(),
            'lines' => [
                [
                    'stock_in_line_id' => $stockInLineId,
                    'product_id' => $device->id,
                    'qc_result' => 'PASS',
                    'stock_item_ids' => [$stockItemId],
                ],
            ],
        ])->assertCreated();

        $this->postJson('/api/stock-outs', [
            'stock_out_number' => 'SOUT-STF-100001',
            'idempotency_key' => 'idem-stf-100001',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-STF-100001',
            'lines' => [
                [
                    'product_id' => $device->id,
                    'qty' => 1,
                    'stock_item_ids' => [$stockItemId],
                ],
            ],
        ])->assertCreated();
    }

    public function test_non_serialized_accessory_can_stock_out_by_quantity_without_stock_item_ids(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create([
            'product_code' => 'BST-OUT-1001',
            'product_type' => 'ACCESSORY',
            'requires_serial_number' => false,
        ]);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-BST-OUT-1001',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_qty' => 5,
                ],
            ],
        ])->assertCreated();

        $this->postJson('/api/stock-outs', [
            'stock_out_number' => 'SOUT-BST-1001',
            'idempotency_key' => 'idem-bst-1001',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-BST-1001',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'qty' => 3,
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'stock_item_id' => null,
            'movement_type' => 'STOCK_OUT',
            'qty_out' => 3,
        ]);
        $this->assertDatabaseCount('stock_items', 0);
    }

    /**
     * @return array{Supplier, Product, int, int, int}
     */
    private function createReceivedDeviceItem(User $admin): array
    {
        Sanctum::actingAs($admin, ['admin-access']);

        $supplier = Supplier::factory()->create();
        $device = Product::factory()->create([
            'product_code' => 'DEV-F4-1001',
            'product_type' => 'DEVICE',
        ]);

        $response = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-F4-100001-'.fake()->numerify('##'),
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $device->id,
                    'received_qty' => 1,
                    'serial_numbers' => ['DEV-F4-SN-'.fake()->numerify('####')],
                ],
            ],
        ])->assertCreated();

        $stockInId = (int) $response->json('data.id');
        $stockInLineId = (int) $response->json('data.lines.0.id');
        $stockItemId = (int) $response->json('data.lines.0.stock_items.0.id');

        $this->assertDatabaseHas('stock_items', [
            'id' => $stockItemId,
            'current_status' => 'RECEIVED',
        ]);

        return [$supplier, $device, $stockItemId, $stockInId, $stockInLineId];
    }
}
