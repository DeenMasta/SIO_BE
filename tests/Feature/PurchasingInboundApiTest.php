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
        $staff = User::factory()->staff()->create();
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

        $this->assertSame(0, $admin->fresh()->unreadNotifications()->count());
        $this->assertSame('purchase-order.created', $staff->fresh()->notifications()->first()?->data['event_type']);
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
        $purchaseOrderLineId = (int) $createPo->json('data.lines.0.id');

        $this->patchJson('/api/purchase-orders/'.$purchaseOrderId.'/issue')->assertOk();

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-900010',
            'stock_in_date' => now()->toDateString(),
            'purchase_order_id' => $purchaseOrderId,
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'purchase_order_line_id' => $purchaseOrderLineId,
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

    public function test_purchase_order_update_replaces_lines_when_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $productOne = Product::factory()->create(['product_type' => 'CONSUMABLE']);
        $productTwo = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $po = $this->postJson('/api/purchase-orders', [
            'po_number' => 'PO-200001',
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'remarks' => 'Original remarks',
            'lines' => [
                [
                    'product_id' => $productOne->id,
                    'ordered_qty' => 2,
                    'unit_price' => 11,
                ],
            ],
        ])->assertCreated();

        $poId = (int) $po->json('data.id');

        $this->patchJson('/api/purchase-orders/'.$poId, [
            'po_date' => now()->addDay()->toDateString(),
            'supplier_id' => $supplier->id,
            'expected_delivery_date' => now()->addDays(2)->toDateString(),
            'remarks' => 'Updated remarks',
            'lines' => [
                [
                    'product_id' => $productTwo->id,
                    'ordered_qty' => 5,
                    'unit_price' => 8.5,
                    'remarks' => 'New line',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.remarks', 'Updated remarks')
            ->assertJsonPath('data.lines.0.product_id', $productTwo->id)
            ->assertJsonPath('data.lines.0.ordered_qty', 5)
            ->assertJsonPath('data.lines.0.subtotal', '42.50');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'remarks' => 'Updated remarks',
        ]);
        $this->assertDatabaseHas('purchase_order_lines', [
            'purchase_order_id' => $poId,
            'product_id' => $productTwo->id,
            'ordered_qty' => 5,
        ]);
        $this->assertContains(
            'purchase-order.updated',
            $staff->fresh()->notifications()->get()->pluck('data.event_type')->all(),
        );
    }

    public function test_purchase_order_delete_notifies_other_active_users(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $po = $this->postJson('/api/purchase-orders', [
            'po_number' => 'PO-DELETE-001',
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'ordered_qty' => 1,
                'unit_price' => 10,
            ]],
        ])->assertCreated();

        $poId = (int) $po->json('data.id');

        $this->deleteJson('/api/purchase-orders/'.$poId)->assertOk();

        $this->assertDatabaseMissing('purchase_orders', ['id' => $poId]);
        $this->assertSame(0, $admin->fresh()->unreadNotifications()->count());
        $this->assertContains(
            'purchase-order.deleted',
            $staff->fresh()->notifications()->get()->pluck('data.event_type')->all(),
        );
    }

    public function test_purchase_order_update_rejects_non_draft_orders(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $po = $this->postJson('/api/purchase-orders', [
            'po_number' => 'PO-200002',
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'ordered_qty' => 2,
                    'unit_price' => 10,
                ],
            ],
        ])->assertCreated();

        $poId = (int) $po->json('data.id');
        $this->patchJson('/api/purchase-orders/'.$poId.'/issue')->assertOk();

        $this->patchJson('/api/purchase-orders/'.$poId, [
            'po_date' => now()->addDay()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'ordered_qty' => 3,
                    'unit_price' => 12,
                ],
            ],
        ])->assertUnprocessable()
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
                    'purchase_order_line_id' => $poLineId,
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
                    'purchase_order_line_id' => $poLineId,
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
        $poLineId = (int) $po->json('data.lines.0.id');
        $this->patchJson('/api/purchase-orders/'.$poId.'/issue')->assertOk();

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-PO-100060-A',
            'stock_in_date' => now()->toDateString(),
            'purchase_order_id' => $poId,
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'purchase_order_line_id' => $poLineId,
                    'received_qty' => 3,
                ],
            ],
        ])->assertUnprocessable();
    }

    public function test_stock_in_requires_purchase_order_line_id_for_po_linked_receiving(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $po = $this->postJson('/api/purchase-orders', [
            'po_number' => 'PO-100061',
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'ordered_qty' => 2,
                'unit_price' => 1,
            ]],
        ])->assertCreated();

        $poId = (int) $po->json('data.id');
        $this->patchJson('/api/purchase-orders/'.$poId.'/issue')->assertOk();

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-PO-100061-A',
            'stock_in_date' => now()->toDateString(),
            'purchase_order_id' => $poId,
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'received_qty' => 1,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.purchase_order_line_id']);
    }

    public function test_purchase_order_show_includes_remaining_qty_and_product_details(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'product_code' => 'DEV-PO-DETAIL',
            'product_name' => 'PO Detail Device',
            'product_type' => 'DEVICE',
        ]);

        Sanctum::actingAs($admin, ['admin-access']);

        $po = $this->postJson('/api/purchase-orders', [
            'po_number' => 'PO-100062',
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'ordered_qty' => 4,
                'unit_price' => 50,
            ]],
        ])->assertCreated();

        $poId = (int) $po->json('data.id');
        $poLineId = (int) $po->json('data.lines.0.id');
        $this->patchJson('/api/purchase-orders/'.$poId.'/issue')->assertOk();

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-PO-100062-A',
            'stock_in_date' => now()->toDateString(),
            'purchase_order_id' => $poId,
            'supplier_id' => $supplier->id,
            'lines' => [[
                'purchase_order_line_id' => $poLineId,
                'received_qty' => 1,
                'serial_numbers' => ['PO-DETAIL-SN-0001'],
            ]],
        ])->assertCreated();

        $this->getJson('/api/purchase-orders/'.$poId)
            ->assertOk()
            ->assertJsonPath('data.lines.0.product_code', 'DEV-PO-DETAIL')
            ->assertJsonPath('data.lines.0.product_name', 'PO Detail Device')
            ->assertJsonPath('data.lines.0.product_type', 'DEVICE')
            ->assertJsonPath('data.lines.0.remaining_qty', 3);
    }

    public function test_purchase_order_export_returns_filtered_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create([
            'supplier_code' => 'SUP-EXP',
            'supplier_name' => 'Export Supplier',
        ]);
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/purchase-orders', [
            'po_number' => 'PO-EXPORT-001',
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'status' => 'DRAFT',
            'remarks' => 'Urgent replenishment',
            'lines' => [[
                'product_id' => $product->id,
                'ordered_qty' => 5,
                'unit_price' => 12.5,
            ]],
        ])->assertCreated();

        $response = $this->get('/api/purchase-orders/export?status=DRAFT&q=PO-EXPORT&format=csv');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('po_number', $content);
        $this->assertStringContainsString('PO-EXPORT-001', $content);
        $this->assertStringContainsString('Export Supplier', $content);
        $this->assertStringContainsString('62.50', $content);
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

    public function test_stock_in_allows_non_serialized_accessory_without_serial_numbers(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'product_code' => 'BST-1001',
            'product_type' => 'ACCESSORY',
            'requires_serial_number' => false,
        ]);

        Sanctum::actingAs($admin, ['admin-access']);

        $response = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-900001-NS',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_qty' => 10,
                ],
            ],
        ])->assertCreated();

        $stockInLineId = (int) $response->json('data.lines.0.id');

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'stock_item_id' => null,
            'reference_id' => $stockInLineId,
            'qty_in' => 10,
            'qty_out' => 0,
        ]);
        $this->assertDatabaseCount('stock_items', 0);
    }

    public function test_stock_in_export_returns_filtered_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create([
            'supplier_code' => 'SUP-SIN',
            'supplier_name' => 'Stock In Export Supplier',
        ]);
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-EXPORT-001',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'remarks' => 'Initial receiving batch',
            'lines' => [[
                'product_id' => $product->id,
                'received_qty' => 7,
            ]],
        ])->assertCreated();

        $response = $this->get('/api/stock-ins/export?q=SIN-EXPORT&format=csv');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('stock_in_number', $content);
        $this->assertStringContainsString('SIN-EXPORT-001', $content);
        $this->assertStringContainsString('Stock In Export Supplier', $content);
        $this->assertStringContainsString('7', $content);
    }

    public function test_legacy_qc_post_with_empty_lines_notifies_other_active_users(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'product_type' => 'CONSUMABLE',
        ]);

        Sanctum::actingAs($admin, ['admin-access']);

        $stockIn = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-QC-LEGACY-001',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'received_qty' => 1,
            ]],
        ])->assertCreated();

        $this->postJson('/api/qc-transactions', [
            'qc_reference_number' => 'QC-LEGACY-001',
            'stock_in_id' => (int) $stockIn->json('data.id'),
            'qc_date' => now()->toDateString(),
            'lines' => [],
        ])->assertCreated();

        $this->assertSame(0, $admin->fresh()->unreadNotifications()->count());
        $this->assertContains(
            'qc-document.posted',
            $staff->fresh()->notifications()->get()->pluck('data.event_type')->all(),
        );
    }

    public function test_qc_document_update_notifies_other_active_users(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'product_type' => 'DEVICE',
            'requires_serial_number' => true,
        ]);
        $product->conditions()->create([
            'condition_name' => 'Screen OK',
        ]);

        Sanctum::actingAs($admin, ['admin-access']);

        $stockIn = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-QC-UPD-001',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'received_qty' => 1,
                'serial_numbers' => ['QC-UPD-0001'],
            ]],
        ])->assertCreated();

        $stockItemId = (int) StockItem::query()->value('id');

        $qcDocument = $this->postJson('/api/qc-documents', [
            'document_number' => 'QC-UPD-001',
            'stock_in_id' => (int) $stockIn->json('data.id'),
            'date' => now()->toDateString(),
            'lines' => [[
                'stock_item_id' => $stockItemId,
                'result' => 'FAILED',
                'checked_conditions' => [],
                'checked_accessories' => [],
                'remarks' => 'Initial fail',
            ]],
        ])->assertCreated();

        $checkId = (int) $qcDocument->json('data.lines.0.id');

        $this->patchJson('/api/qc-documents/'.$qcDocument->json('data.id'), [
            'date' => now()->addDay()->toDateString(),
            'remarks' => 'Updated after re-check',
            'lines' => [[
                'id' => $checkId,
                'checked_conditions' => ['Screen OK'],
                'checked_accessories' => [],
                'remarks' => 'Passed on second inspection',
            ]],
        ])->assertOk();

        $this->assertContains(
            'qc-document.updated',
            $staff->fresh()->notifications()->get()->pluck('data.event_type')->all(),
        );
    }

    public function test_qc_document_export_returns_filtered_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'product_type' => 'DEVICE',
            'requires_serial_number' => true,
        ]);

        Sanctum::actingAs($admin, ['admin-access']);

        $stockIn = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-QC-EXPORT',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'received_qty' => 1,
                'serial_numbers' => ['QC-EXP-0001'],
            ]],
        ])->assertCreated();

        $stockInId = (int) $stockIn->json('data.id');
        $stockItemId = (int) StockItem::query()->value('id');

        $this->postJson('/api/qc-documents', [
            'document_number' => 'QC-EXPORT-001',
            'stock_in_id' => $stockInId,
            'date' => now()->toDateString(),
            'lines' => [[
                'stock_item_id' => $stockItemId,
                'result' => 'PASSED',
                'checked_conditions' => [],
                'checked_accessories' => [],
            ]],
        ])->assertCreated();

        $response = $this->get('/api/qc-documents/export?q=QC-EXPORT&status=POSTED&format=csv');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('document_number', $content);
        $this->assertStringContainsString('QC-EXPORT-001', $content);
        $this->assertStringContainsString('SIN-QC-EXPORT', $content);
        $this->assertStringContainsString($admin->name, $content);
    }
}
