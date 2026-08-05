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

class ExceptionsReturnsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_complete_repair(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createInStockDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-100001',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
            'repair_flow' => 'INTERNAL',
            'issue_description' => 'Unit cannot power on',
        ])->assertCreated();

        $repairId = (int) $repair->json('data.id');

        $this->assertDatabaseHas('stock_items', [
            'id' => $stockItemId,
            'current_status' => 'UNDER_REPAIR',
        ]);

        $this->patchJson('/api/repairs/'.$repairId.'/status', [
            'repair_status' => 'COMPLETED',
        ])->assertOk();

        $this->assertDatabaseHas('stock_items', [
            'id' => $stockItemId,
            'current_status' => 'IN_STOCK',
            'is_available' => 1,
        ]);
    }

    public function test_return_to_supplier_allows_received_item_only(): void
    {
        $admin = User::factory()->admin()->create();
        [$receivedItemId, $productId, $supplierId] = $this->createReceivedDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/return-to-suppliers', [
            'rts_transaction_number' => 'RTS-100001',
            'supplier_id' => $supplierId,
            'return_date' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $productId,
                    'stock_item_id' => $receivedItemId,
                    'qty' => 1,
                    'reason_for_return' => 'PHYSICAL_DAMAGE',
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('stock_items', [
            'id' => $receivedItemId,
            'current_status' => 'RETURNED_TO_SUPPLIER',
        ]);
    }

    public function test_customer_return_requires_delivered_item_and_updates_status(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId, $productId, $customerId, $stockOutId, $stockOutLineId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/customer-returns', [
            'return_transaction_number' => 'CRT-100001',
            'return_date' => now()->toDateString(),
            'customer_id' => $customerId,
            'original_invoice_number' => 'INV-CR-100001',
            'original_stock_out_id' => $stockOutId,
            'lines' => [
                [
                    'original_stock_out_line_id' => $stockOutLineId,
                    'product_id' => $productId,
                    'stock_item_id' => $stockItemId,
                    'qty' => 1,
                    'reason_for_return' => 'WARRANTY_CLAIM',
                    'condition_on_return' => 'Damaged',
                    'next_action' => 'REPLACE',
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('stock_items', [
            'id' => $stockItemId,
            'current_status' => 'RETURNED',
            'is_available' => 0,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'stock_item_id' => $stockItemId,
            'movement_type' => 'CUSTOMER_RETURN',
        ]);

        $this->assertTrue(StockMovement::query()->count() > 0);
    }

    public function test_customer_return_can_dispose_a_delivered_item(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId, $productId, $customerId, $stockOutId, $stockOutLineId] = $this->createDeliveredDevice($admin);
        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/customer-returns', [
            'return_transaction_number' => 'CRT-DISPOSE-001',
            'return_date' => now()->toDateString(),
            'customer_id' => $customerId,
            'original_invoice_number' => 'INV-DISPOSE-001',
            'original_stock_out_id' => $stockOutId,
            'lines' => [[
                'original_stock_out_line_id' => $stockOutLineId,
                'product_id' => $productId,
                'stock_item_id' => $stockItemId,
                'qty' => 1,
                'reason_for_return' => 'PHYSICAL_DAMAGE',
                'next_action' => 'DISPOSE',
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('stock_items', [
            'id' => $stockItemId,
            'current_status' => 'SCRAPPED',
            'is_available' => 0,
        ]);
    }

    public function test_customer_exchange_adds_a_free_sale_order_line_and_dispatches_it(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create([
            'product_code' => 'EXCHANGE-CONSUMABLE-001',
            'product_type' => 'CONSUMABLE',
            'requires_serial_number' => false,
        ]);
        Sanctum::actingAs($admin, ['admin-access']);

        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $product->id,
            'stock_item_id' => null,
            'movement_type' => 'STOCK_IN',
            'reference_table' => 'test_seed',
            'reference_id' => 8001,
            'qty_in' => 2,
            'qty_out' => 0,
            'to_status' => 'IN_STOCK',
            'performed_by' => $admin->id,
        ]);

        $saleOrder = $this->postJson('/api/sale-orders', [
            'so_number' => 'SO-EXCHANGE-001',
            'so_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-EXCHANGE-001',
            'lines' => [[
                'product_id' => $product->id,
                'ordered_qty' => 1,
                'unit_price' => 50,
            ]],
        ])->assertCreated();
        $saleOrderId = (int) $saleOrder->json('data.id');
        $originalSaleOrderLineId = (int) $saleOrder->json('data.lines.0.id');

        $this->patchJson('/api/sale-orders/'.$saleOrderId.'/confirm')->assertOk();
        $originalStockOut = $this->postJson('/api/stock-outs', [
            'sale_order_id' => $saleOrderId,
            'stock_out_number' => 'SOUT-EXCHANGE-ORIGINAL',
            'idempotency_key' => 'idem-exchange-original',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'lines' => [[
                'product_id' => $product->id,
                'sale_order_line_id' => $originalSaleOrderLineId,
                'qty' => 1,
            ]],
        ])->assertCreated();
        $originalStockOutId = (int) $originalStockOut->json('data.id');
        $originalStockOutLineId = (int) $originalStockOut->json('data.lines.0.id');

        $return = $this->postJson('/api/customer-returns', [
            'return_transaction_number' => 'CRT-EXCHANGE-001',
            'return_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'original_invoice_number' => 'INV-EXCHANGE-001',
            'original_stock_out_id' => $originalStockOutId,
            'lines' => [[
                'original_stock_out_line_id' => $originalStockOutLineId,
                'product_id' => $product->id,
                'qty' => 1,
                'reason_for_return' => 'WARRANTY_CLAIM',
                'next_action' => 'RESTOCK',
            ]],
        ])->assertCreated();
        $returnId = (int) $return->json('data.id');
        $returnLineId = (int) $return->json('data.lines.0.id');

        $exchange = $this->postJson('/api/customer-returns/'.$returnId.'/exchange', [
            'lines' => [[
                'customer_return_line_id' => $returnLineId,
                'replacement_product_id' => $product->id,
                'qty' => 1,
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.sale_order_id', $saleOrderId);
        $exchangeId = (int) $exchange->json('data.id');
        $exchangeSaleOrderLineId = (int) $exchange->json('data.lines.0.sale_order_line_id');

        $this->assertDatabaseHas('sale_orders', ['id' => $saleOrderId, 'status' => 'CONFIRMED']);
        $this->assertDatabaseHas('sale_order_lines', [
            'id' => $originalSaleOrderLineId,
            'fulfilled_qty' => 1,
            'line_type' => 'SALE',
        ]);
        $this->assertDatabaseHas('sale_order_lines', [
            'id' => $exchangeSaleOrderLineId,
            'line_type' => 'EXCHANGE',
            'is_free' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
            'fulfilled_qty' => 0,
        ]);

        $this->postJson('/api/stock-outs', [
            'sale_order_id' => $saleOrderId,
            'customer_exchange_id' => $exchangeId,
            'stock_out_number' => 'SOUT-EXCHANGE-REPLACEMENT',
            'idempotency_key' => 'idem-exchange-replacement',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'lines' => [[
                'product_id' => $product->id,
                'sale_order_line_id' => $exchangeSaleOrderLineId,
                'qty' => 1,
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.customer_exchange_id', $exchangeId);

        $this->assertDatabaseHas('sale_orders', ['id' => $saleOrderId, 'status' => 'FULFILLED']);
        $this->assertDatabaseHas('sale_order_lines', ['id' => $originalSaleOrderLineId, 'fulfilled_qty' => 1]);
        $this->assertDatabaseHas('sale_order_lines', ['id' => $exchangeSaleOrderLineId, 'fulfilled_qty' => 1]);
        $this->assertDatabaseHas('customer_exchanges', ['id' => $exchangeId, 'status' => 'DISPATCHED']);
    }

    public function test_staff_can_create_repair_rts_and_customer_return(): void
    {
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();
        [$repairStockItemId] = $this->createInStockDevice($admin);
        [$returnStockItemId, $deliveredProductId, $customerId, $stockOutId, $stockOutLineId] = $this->createDeliveredDevice($admin);
        [$receivedItemId, $receivedProductId, $supplierId] = $this->createReceivedDevice($admin);

        Sanctum::actingAs($staff, ['staff-access']);

        $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-100002',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $repairStockItemId,
            'repair_flow' => 'INTERNAL',
            'issue_description' => 'Broken port',
        ])->assertCreated();

        $this->postJson('/api/return-to-suppliers', [
            'rts_transaction_number' => 'RTS-100002',
            'supplier_id' => $supplierId,
            'return_date' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $receivedProductId,
                    'stock_item_id' => $receivedItemId,
                    'qty' => 1,
                    'reason_for_return' => 'FUNCTIONAL_ISSUE',
                ],
            ],
        ])->assertCreated();

        $this->postJson('/api/customer-returns', [
            'return_transaction_number' => 'CRT-100002',
            'return_date' => now()->toDateString(),
            'customer_id' => $customerId,
            'original_invoice_number' => 'INV-CR-100001',
            'original_stock_out_id' => $stockOutId,
            'lines' => [
                [
                    'original_stock_out_line_id' => $stockOutLineId,
                    'product_id' => $deliveredProductId,
                    'stock_item_id' => $returnStockItemId,
                    'qty' => 1,
                    'reason_for_return' => 'WARRANTY_CLAIM',
                    'condition_on_return' => 'Damaged',
                    'next_action' => 'REPAIR',
                ],
            ],
        ])->assertCreated();
    }

    public function test_repair_export_returns_filtered_csv(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createInStockDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-EXPORT-001',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
            'repair_flow' => 'INTERNAL',
            'issue_description' => 'Battery issue',
        ])->assertCreated();

        $response = $this->get('/api/repairs/export?q=RPR-EXPORT&status=OPEN&format=csv');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('repair_transaction_number', $content);
        $this->assertStringContainsString('RPR-EXPORT-001', $content);
        $this->assertStringContainsString('Battery issue', $content);
    }

    public function test_return_to_supplier_export_returns_filtered_csv(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['admin-access']);
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'product_code' => 'DEV-RTS-EXPORT',
            'product_type' => 'DEVICE',
        ]);

        $stockIn = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-RTS-EXPORT',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'received_qty' => 1,
                'serial_numbers' => ['SN-RTS-EXPORT-001'],
            ]],
        ])->assertCreated();

        $stockInId = (int) $stockIn->json('data.id');
        $stockInLineId = (int) $stockIn->json('data.lines.0.id');
        $receivedItemId = (int) $stockIn->json('data.lines.0.stock_items.0.id');

        $this->postJson('/api/return-to-suppliers', [
            'rts_transaction_number' => 'RTS-EXPORT-001',
            'supplier_id' => $supplier->id,
            'stock_in_id' => $stockInId,
            'return_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'stock_item_id' => $receivedItemId,
                'stock_in_line_id' => $stockInLineId,
                'qty' => 1,
                'reason_for_return' => 'PHYSICAL_DAMAGE',
            ]],
        ])->assertCreated();

        $response = $this->get('/api/return-to-suppliers/export?q=RTS-EXPORT&status=POSTED&format=csv');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('rts_transaction_number', $content);
        $this->assertStringContainsString('RTS-EXPORT-001', $content);
        $this->assertStringContainsString('1', $content);
    }

    public function test_customer_return_export_returns_filtered_csv(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId, $productId, $customerId, $stockOutId, $stockOutLineId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/customer-returns', [
            'return_transaction_number' => 'CRT-EXPORT-001',
            'return_date' => now()->toDateString(),
            'customer_id' => $customerId,
            'original_invoice_number' => 'INV-CR-EXPORT',
            'original_stock_out_id' => $stockOutId,
            'lines' => [[
                'original_stock_out_line_id' => $stockOutLineId,
                'product_id' => $productId,
                'stock_item_id' => $stockItemId,
                'qty' => 1,
                'reason_for_return' => 'WARRANTY_CLAIM',
                'condition_on_return' => 'Damaged',
                'next_action' => 'REPLACE',
            ]],
        ])->assertCreated();

        $response = $this->get('/api/customer-returns/export?q=CRT-EXPORT&status=POSTED&format=csv');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('return_transaction_number', $content);
        $this->assertStringContainsString('CRT-EXPORT-001', $content);
        $this->assertStringContainsString('INV-CR-EXPORT', $content);
    }

    public function test_serial_search_returns_product_and_current_customer_for_delivered_item(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId, $productId, $customerId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $stockItem = StockItem::query()->findOrFail($stockItemId);
        $customer = Customer::query()->findOrFail($customerId);
        $product = Product::query()->findOrFail($productId);

        $response = $this->getJson('/api/search/serials?query='.$stockItem->serial_number.'&per_page=20')
            ->assertOk();

        $response->assertJsonPath('data.0.id', $stockItemId);
        $response->assertJsonPath('data.0.product.id', $productId);
        $response->assertJsonPath('data.0.product.product_code', $product->product_code);
        $response->assertJsonPath('data.0.current_customer_id', $customerId);
        $response->assertJsonPath('data.0.current_customer_name', $customer->customer_name);
    }

    /**
     * @return array{int, int, int}
     */
    protected function createReceivedDevice(User $admin): array
    {
        Sanctum::actingAs($admin, ['admin-access']);

        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'product_code' => 'DEV-RTS-1001',
            'product_type' => 'DEVICE',
        ]);

        $stockIn = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-RTS-'.fake()->numerify('######'),
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_qty' => 1,
                    'serial_numbers' => ['SN-RTS-'.fake()->numerify('######')],
                ],
            ],
        ])->assertCreated();

        $stockItemId = (int) $stockIn->json('data.lines.0.stock_items.0.id');

        return [$stockItemId, (int) $product->id, (int) $supplier->id];
    }

    /**
     * @return array{int, int, int, int, int}
     */
    protected function createDeliveredDevice(User $admin): array
    {
        Sanctum::actingAs($admin, ['admin-access']);

        $supplier = Supplier::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create([
            'product_code' => 'DEV-RET-'.fake()->numerify('######'),
            'product_type' => 'DEVICE',
        ]);

        $stockIn = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-RET-'.fake()->numerify('######'),
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_qty' => 1,
                    'serial_numbers' => ['SN-RET-'.fake()->numerify('######')],
                ],
            ],
        ])->assertCreated();

        $stockInId = (int) $stockIn->json('data.id');
        $stockItemId = (int) $stockIn->json('data.lines.0.stock_items.0.id');

        $this->postJson('/api/qc-documents', [
            'document_number' => 'QC-RET-'.fake()->numerify('######'),
            'stock_in_id' => $stockInId,
            'date' => now()->toDateString(),
            'lines' => [
                [
                    'stock_item_id' => $stockItemId,
                    'result' => 'PASSED',
                    'checked_conditions' => [],
                    'checked_accessories' => [],
                ],
            ],
        ])->assertCreated();

        $stockOut = $this->postJson('/api/stock-outs', [
            'stock_out_number' => 'SOUT-RET-'.fake()->numerify('######'),
            'idempotency_key' => 'idem-ret-'.fake()->numerify('######'),
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'qty' => 1,
                    'stock_item_ids' => [$stockItemId],
                ],
            ],
        ])->assertCreated();

        $stockOutId = (int) $stockOut->json('data.id');
        $stockOutLineId = (int) $stockOut->json('data.lines.0.id');

        return [$stockItemId, (int) $product->id, (int) $customer->id, $stockOutId, $stockOutLineId];
    }
}
