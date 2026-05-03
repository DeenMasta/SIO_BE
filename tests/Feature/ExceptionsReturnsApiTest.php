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

class ExceptionsReturnsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_complete_repair(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-100001',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
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

    public function test_staff_can_create_repair_rts_and_customer_return(): void
    {
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();
        [$repairStockItemId] = $this->createDeliveredDevice($admin);
        [$returnStockItemId, $deliveredProductId, $customerId, $stockOutId, $stockOutLineId] = $this->createDeliveredDevice($admin);
        [$receivedItemId, $receivedProductId, $supplierId] = $this->createReceivedDevice($admin);

        Sanctum::actingAs($staff, ['staff-access']);

        $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-100002',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $repairStockItemId,
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
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-EXPORT-001',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
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
