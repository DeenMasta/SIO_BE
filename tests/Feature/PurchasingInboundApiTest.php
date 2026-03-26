<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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
            ->assertJsonPath('data.lines.0.received_qty', 0)
            ->assertJsonPath('data.lines.0.subtotal', '55.00');
    }

    public function test_staff_can_create_purchase_order_and_post_stock_in(): void
    {
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($staff, ['staff-access']);

        $createPo = $this->postJson('/api/purchase-orders', [
            'po_number' => 'PO-100002',
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'ordered_qty' => 3,
                    'unit_price' => 10,
                ],
            ],
        ])->assertCreated();

        $purchaseOrderId = (int) $createPo->json('data.id');

        $this->patchJson('/api/purchase-orders/'.$purchaseOrderId.'/issue')->assertOk();

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-900010',
            'stock_in_date' => now()->toDateString(),
            'purchase_order_id' => $purchaseOrderId,
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_qty' => 3,
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $purchaseOrderId,
            'status' => 'COMPLETED',
        ]);
    }

    public function test_purchase_order_transitions_support_valid_paths_and_write_audit_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $purchaseOrder = PurchaseOrder::factory()->create(['status' => 'DRAFT']);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->patchJson('/api/purchase-orders/'.$purchaseOrder->id.'/issue')
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED');

        $this->patchJson('/api/purchase-orders/'.$purchaseOrder->id.'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertDatabaseHas('audit_logs', [
            'entity_name' => 'PurchaseOrder',
            'entity_id' => $purchaseOrder->id,
        ]);
        $this->assertGreaterThanOrEqual(2, AuditLog::query()->where('entity_name', 'PurchaseOrder')->where('entity_id', $purchaseOrder->id)->count());
    }

    public function test_purchase_order_invalid_transition_returns_422(): void
    {
        $admin = User::factory()->admin()->create();
        $purchaseOrder = PurchaseOrder::factory()->create(['status' => 'DRAFT']);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->patchJson('/api/purchase-orders/'.$purchaseOrder->id.'/complete')
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error');
    }

    public function test_stock_in_tracks_partial_receive_then_auto_completes_po(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $po = $this->postJson('/api/purchase-orders', [
            'po_number' => 'PO-100050',
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'ordered_qty' => 5,
                    'unit_price' => 1.5,
                ],
            ],
        ])->assertCreated();

        $poId = (int) $po->json('data.id');
        $poLineId = (int) $po->json('data.lines.0.id');

        $this->patchJson('/api/purchase-orders/'.$poId.'/issue')->assertOk();

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-PO-100050-A',
            'stock_in_date' => now()->toDateString(),
            'purchase_order_id' => $poId,
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_qty' => 2,
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('purchase_order_lines', [
            'id' => $poLineId,
            'received_qty' => 2,
        ]);
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'status' => 'ISSUED',
        ]);

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-PO-100050-B',
            'stock_in_date' => now()->toDateString(),
            'purchase_order_id' => $poId,
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_qty' => 3,
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('purchase_order_lines', [
            'id' => $poLineId,
            'received_qty' => 5,
        ]);
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'status' => 'COMPLETED',
        ]);
    }

    public function test_stock_in_rejects_receive_beyond_ordered_qty(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $po = $this->postJson('/api/purchase-orders', [
            'po_number' => 'PO-100060',
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'ordered_qty' => 2,
                    'unit_price' => 1,
                ],
            ],
        ])->assertCreated();

        $poId = (int) $po->json('data.id');
        $this->patchJson('/api/purchase-orders/'.$poId.'/issue')->assertOk();

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-PO-100060-A',
            'stock_in_date' => now()->toDateString(),
            'purchase_order_id' => $poId,
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_qty' => 3,
                ],
            ],
        ])->assertUnprocessable();
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
