<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchasingInboundApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_purchase_order_with_lines(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/purchase-orders', [
            'po_number' => 'PO-100001',
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'status' => 'DRAFT',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'ordered_qty' => 10,
                    'unit_price' => 5.5,
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.lines.0.ordered_qty', 10)
            ->assertJsonPath('data.lines.0.subtotal', '55.00');
    }

    public function test_staff_can_view_purchase_order_but_cannot_create(): void
    {
        $staff = User::factory()->staff()->create();
        $po = PurchaseOrder::factory()->create();
        $product = Product::factory()->create();

        Sanctum::actingAs($staff, ['staff-access']);

        $this->getJson('/api/purchase-orders/'.$po->id)->assertOk();

        $this->postJson('/api/purchase-orders', [
            'po_number' => 'PO-100002',
            'po_date' => now()->toDateString(),
            'supplier_id' => $po->supplier_id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'ordered_qty' => 1,
                    'unit_price' => 10,
                ],
            ],
        ])->assertForbidden();
    }

    public function test_admin_can_post_stock_in_and_create_ledger_records(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $device = Product::factory()->create(['product_code' => 'DEV-1001', 'product_type' => 'DEVICE']);
        $accessory = Product::factory()->create(['product_code' => 'ACC-1001', 'product_type' => 'ACCESSORY']);
        $consumable = Product::factory()->create(['product_code' => 'CON-1001', 'product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $response = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-900001',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $device->id,
                    'received_qty' => 2,
                    'serial_numbers' => ['DEV-SN-001', 'DEV-SN-002'],
                ],
                [
                    'product_id' => $accessory->id,
                    'received_qty' => 2,
                ],
                [
                    'product_id' => $consumable->id,
                    'received_qty' => 5,
                ],
            ],
        ])->assertCreated();

        $stockInId = (int) $response->json('data.id');

        $this->assertDatabaseHas('stock_in', [
            'id' => $stockInId,
            'status' => 'POSTED',
        ]);

        $this->assertSame(4, StockItem::query()->count());
        $this->assertSame(5, StockMovement::query()->count());
    }

    public function test_duplicate_serial_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $device = Product::factory()->create(['product_type' => 'DEVICE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-900002',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $device->id,
                    'received_qty' => 1,
                    'serial_numbers' => ['DUPLICATE-001'],
                ],
            ],
        ])->assertCreated();

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-900003',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $device->id,
                    'received_qty' => 1,
                    'serial_numbers' => ['DUPLICATE-001'],
                ],
            ],
        ])->assertUnprocessable();
    }
}
