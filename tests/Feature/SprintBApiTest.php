<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SprintBApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_low_stock_endpoint_sorted_and_dashboard_has_low_stock_count(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();

        $p1 = Product::factory()->create(['reorder_level' => 10, 'product_type' => 'CONSUMABLE']);
        $p2 = Product::factory()->create(['reorder_level' => 20, 'product_type' => 'CONSUMABLE']);
        $p3 = Product::factory()->create(['reorder_level' => 5, 'product_type' => 'CONSUMABLE']);

        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $p1->id,
            'stock_item_id' => null,
            'movement_type' => 'STOCK_IN',
            'reference_table' => 'test_seed',
            'reference_id' => 1,
            'qty_in' => 4,
            'qty_out' => 0,
            'to_status' => 'IN_STOCK',
            'performed_by' => $admin->id,
        ]);
        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $p2->id,
            'stock_item_id' => null,
            'movement_type' => 'STOCK_IN',
            'reference_table' => 'test_seed',
            'reference_id' => 2,
            'qty_in' => 3,
            'qty_out' => 0,
            'to_status' => 'IN_STOCK',
            'performed_by' => $admin->id,
        ]);
        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $p3->id,
            'stock_item_id' => null,
            'movement_type' => 'STOCK_IN',
            'reference_table' => 'test_seed',
            'reference_id' => 3,
            'qty_in' => 6,
            'qty_out' => 0,
            'to_status' => 'IN_STOCK',
            'performed_by' => $admin->id,
        ]);

        Sanctum::actingAs($staff, ['staff-access']);

        $response = $this->getJson('/api/reports/low-stock')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $rows = $response->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame($p2->id, (int) $rows[0]['product_id']);
        $this->assertSame($p1->id, (int) $rows[1]['product_id']);

        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.low_stock_count', 2);

        Sanctum::actingAs($admin, ['admin-access']);
        $this->assertTrue(true);
    }

    public function test_dashboard_operational_metrics_support_date_filter(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        PurchaseOrder::factory()->create([
            'expected_delivery_date' => now()->subDay()->toDateString(),
            'status' => 'ISSUED',
            'supplier_id' => $supplier->id,
            'created_by' => $admin->id,
        ]);

        $stockInResponse = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-SPB-100001',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_qty' => 5,
                ],
            ],
        ])->assertCreated();

        $stockInId = (int) $stockInResponse->json('data.id');
        $stockInLineId = (int) $stockInResponse->json('data.lines.0.id');

        $this->postJson('/api/qc-transactions', [
            'qc_reference_number' => 'QC-SPB-100000',
            'stock_in_id' => $stockInId,
            'qc_date' => now()->toDateString(),
            'lines' => [
                [
                    'stock_in_line_id' => $stockInLineId,
                    'product_id' => $product->id,
                    'qc_result' => 'PASS',
                    'qty_pass' => 5,
                    'qty_fail' => 0,
                ],
            ],
        ])->assertCreated();

        $this->postJson('/api/stock-outs', [
            'stock_out_number' => 'SOUT-SPB-100001',
            'idempotency_key' => 'idem-spb-100001',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-SPB-100001',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'qty' => 2,
                ],
            ],
        ])->assertCreated();

        Sanctum::actingAs($staff, ['staff-access']);

        $this->getJson('/api/dashboard/summary?date_from='.now()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'open_po_count',
                    'overdue_po_count',
                    'stock_in_trend',
                    'qc_pass_fail_trend',
                    'stock_out_trend',
                    'top_moved_products',
                ],
            ]);
    }

    public function test_non_serialized_stock_out_rejects_insufficient_balance_with_422(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $product->id,
            'stock_item_id' => null,
            'movement_type' => 'STOCK_IN',
            'reference_table' => 'test_seed',
            'reference_id' => 10,
            'qty_in' => 1,
            'qty_out' => 0,
            'to_status' => 'IN_STOCK',
            'performed_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/stock-outs', [
            'stock_out_number' => 'SOUT-SPB-100002',
            'idempotency_key' => 'idem-spb-100002',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-SPB-100002',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'qty' => 2,
                ],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'movement_type' => 'STOCK_IN',
            'qty_in' => 1,
        ]);
    }

    public function test_report_pack_v1_endpoints_are_available_for_staff_with_filters_and_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $po = $this->postJson('/api/purchase-orders', [
            'po_number' => 'PO-SPB-100001',
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'ordered_qty' => 3,
                'unit_price' => 100,
            ]],
        ])->assertCreated();

        $poId = (int) $po->json('data.id');
        $poLineId = (int) $po->json('data.lines.0.id');
        $this->patchJson('/api/purchase-orders/'.$poId.'/issue')->assertOk();

        $stockIn = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-SPB-100003',
            'stock_in_date' => now()->toDateString(),
            'purchase_order_id' => $poId,
            'supplier_id' => $supplier->id,
            'lines' => [[
                'purchase_order_line_id' => $poLineId,
                'received_qty' => 3,
            ]],
        ])->assertCreated();

        $stockInId = (int) $stockIn->json('data.id');
        $stockInLineId = (int) $stockIn->json('data.lines.0.id');

        $this->postJson('/api/qc-transactions', [
            'qc_reference_number' => 'QC-SPB-100001',
            'stock_in_id' => $stockInId,
            'qc_date' => now()->toDateString(),
            'lines' => [[
                'stock_in_line_id' => $stockInLineId,
                'product_id' => $product->id,
                'qc_result' => 'PASS',
                'qty_pass' => 3,
                'qty_fail' => 0,
            ]],
        ])->assertCreated();

        $stockOut = $this->postJson('/api/stock-outs', [
            'stock_out_number' => 'SOUT-SPB-100003',
            'idempotency_key' => 'idem-spb-100003',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-SPB-100003',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 1,
            ]],
        ])->assertCreated();

        $stockOutId = (int) $stockOut->json('data.id');
        $stockOutLineId = (int) $stockOut->json('data.lines.0.id');

        $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-SPB-100001',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => null,
            'issue_description' => 'N/A',
        ])->assertUnprocessable();

        Sanctum::actingAs($staff, ['staff-access']);

        $this->getJson('/api/reports/stock-balance?per_page=10')->assertOk();
        $this->getJson('/api/reports/stock-card?product_id='.$product->id)->assertOk();
        $this->getJson('/api/reports/low-stock?per_page=10')->assertOk();
        $this->getJson('/api/reports/purchase-orders/summary?po_number=PO-SPB')->assertOk();
        $this->getJson('/api/reports/purchase-orders/open')->assertOk();
        $this->getJson('/api/reports/purchase-orders/aging')->assertOk();
        $this->getJson('/api/reports/stock-in/by-supplier-do?supplier_id='.$supplier->id)->assertOk();
        $this->getJson('/api/reports/qc/pass-fail')->assertOk();
        $this->getJson('/api/reports/stock-out/by-invoice-customer?customer_id='.$customer->id.'&invoice_number=INV-SPB')->assertOk();
        $this->getJson('/api/reports/repairs/summary')->assertOk();
        $this->getJson('/api/reports/rts/summary')->assertOk();
        $this->getJson('/api/reports/customer-returns/summary')->assertOk();

        $this->assertDatabaseHas('stock_out_lines', [
            'id' => $stockOutLineId,
            'stock_out_id' => $stockOutId,
        ]);
    }
}
