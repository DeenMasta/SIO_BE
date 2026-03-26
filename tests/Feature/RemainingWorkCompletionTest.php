<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RemainingWorkCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_return_supports_all_next_action_paths(): void
    {
        $admin = User::factory()->admin()->create();

        $paths = [
            'RESTOCK' => 'IN_STOCK',
            'REPAIR' => 'UNDER_REPAIR',
            'REPLACE' => 'RETURNED',
            'SCRAP' => 'RETURNED_TO_SUPPLIER',
        ];

        $counter = 1;
        foreach ($paths as $nextAction => $expectedStatus) {
            [$stockItemId, $productId, $customerId, $stockOutId, $stockOutLineId] = $this->createDeliveredDevice($admin);

            Sanctum::actingAs($admin, ['admin-access']);

            $this->postJson('/api/customer-returns', [
                'return_transaction_number' => 'CRT-NA-'.str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
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
                        'condition_on_return' => 'Checked',
                        'next_action' => $nextAction,
                    ],
                ],
            ])->assertCreated();

            $this->assertDatabaseHas('stock_items', [
                'id' => $stockItemId,
                'current_status' => $expectedStatus,
            ]);

            $counter++;
        }
    }

    public function test_exception_reason_taxonomy_rejects_unknown_reason(): void
    {
        $admin = User::factory()->admin()->create();
        [$receivedItemId, $productId, $supplierId] = $this->createReceivedDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/return-to-suppliers', [
            'rts_transaction_number' => 'RTS-TAX-100001',
            'supplier_id' => $supplierId,
            'return_date' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $productId,
                    'stock_item_id' => $receivedItemId,
                    'qty' => 1,
                    'reason_for_return' => 'NOT_ALLOWED_REASON',
                ],
            ],
        ])->assertUnprocessable();
    }

    public function test_exception_cancellation_writes_movement_and_audit_rows(): void
    {
        $admin = User::factory()->admin()->create();
        [$receivedItemId, $receivedProductId, $supplierId] = $this->createReceivedDevice($admin);
        [$stockItemId, $productId, $customerId, $stockOutId, $stockOutLineId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $rts = $this->postJson('/api/return-to-suppliers', [
            'rts_transaction_number' => 'RTS-CAN-100001',
            'supplier_id' => $supplierId,
            'return_date' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $receivedProductId,
                    'stock_item_id' => $receivedItemId,
                    'qty' => 1,
                    'reason_for_return' => 'PHYSICAL_DAMAGE',
                ],
            ],
        ])->assertCreated();

        $customerReturn = $this->postJson('/api/customer-returns', [
            'return_transaction_number' => 'CRT-CAN-100001',
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
                    'condition_on_return' => 'Used',
                    'next_action' => 'REPAIR',
                ],
            ],
        ])->assertCreated();

        $this->patchJson('/api/return-to-suppliers/'.$rts->json('data.id').'/cancel', [
            'remarks' => 'cancelled in test',
        ])->assertOk();

        $this->patchJson('/api/customer-returns/'.$customerReturn->json('data.id').'/cancel', [
            'remarks' => 'cancelled in test',
        ])->assertOk();

        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => 'RETURN_TO_SUPPLIER_CANCELLED',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => 'CUSTOMER_RETURN_CANCELLED',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'entity_name' => 'ReturnToSupplier',
            'action' => 'CANCEL',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'entity_name' => 'CustomerReturn',
            'action' => 'CANCEL',
        ]);
    }

    public function test_search_endpoints_support_all_key_fields_with_pagination(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin, ['admin-access']);

        $product = Product::factory()->create([
            'product_code' => 'PRD-SRCH-100001',
            'product_name' => 'Searchable Device',
            'product_type' => 'DEVICE',
        ]);

        $supplier = Supplier::factory()->create();
        $stockIn = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-SRCH-100001',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'delivery_order_number' => 'DO-SRCH-100001',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_qty' => 1,
                    'serial_numbers' => ['SER-SRCH-100001'],
                ],
            ],
        ])->assertCreated();

        $purchaseOrder = $this->postJson('/api/purchase-orders', [
            'po_number' => 'PO-SRCH-100001',
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'ordered_qty' => 1,
                    'unit_price' => 100,
                ],
            ],
        ])->assertCreated();

        $stockInId = (int) $stockIn->json('data.id');
        $stockInLineId = (int) $stockIn->json('data.lines.0.id');
        $stockItemId = (int) $stockIn->json('data.lines.0.stock_items.0.id');

        $this->postJson('/api/qc-transactions', [
            'qc_reference_number' => 'QC-SRCH-100001',
            'stock_in_id' => $stockInId,
            'qc_date' => now()->toDateString(),
            'lines' => [
                [
                    'stock_in_line_id' => $stockInLineId,
                    'product_id' => $product->id,
                    'qc_result' => 'PASS',
                    'stock_item_ids' => [$stockItemId],
                ],
            ],
        ])->assertCreated();

        $customer = Customer::factory()->create();
        $this->postJson('/api/stock-outs', [
            'stock_out_number' => 'SOUT-SRCH-100001',
            'idempotency_key' => 'idem-srch-100001',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-SRCH-100001',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'qty' => 1,
                    'stock_item_ids' => [$stockItemId],
                ],
            ],
        ])->assertCreated();

        $this->getJson('/api/search/products?query=PRD-SRCH&per_page=10')->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/search/serials?query=SER-SRCH&per_page=10')->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/search/invoices?query=INV-SRCH&per_page=10')->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/search/purchase-orders?query=PO-SRCH&per_page=10')->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/search/delivery-orders?query=DO-SRCH&per_page=10')->assertOk()->assertJsonPath('meta.pagination.total', 1);

        $this->assertNotNull($purchaseOrder->json('data.id'));
    }

    public function test_exception_summary_reports_include_aging_fields_and_bucket_filter(): void
    {
        $admin = User::factory()->admin()->create();
        [$receivedItemId, $receivedProductId, $supplierId] = $this->createReceivedDevice($admin);
        [$repairStockItemId] = $this->createDeliveredDevice($admin);
        [$returnStockItemId, $productId, $customerId, $stockOutId, $stockOutLineId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-AGE-100001',
            'repair_date' => now()->subDays(40)->toDateString(),
            'stock_item_id' => $repairStockItemId,
            'issue_description' => 'Needs aging bucket',
        ])->assertCreated();

        $this->postJson('/api/return-to-suppliers', [
            'rts_transaction_number' => 'RTS-AGE-100001',
            'supplier_id' => $supplierId,
            'return_date' => now()->subDays(20)->toDateString(),
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
            'return_transaction_number' => 'CRT-AGE-100001',
            'return_date' => now()->subDays(5)->toDateString(),
            'customer_id' => $customerId,
            'original_invoice_number' => 'INV-CR-100001',
            'original_stock_out_id' => $stockOutId,
            'lines' => [
                [
                    'original_stock_out_line_id' => $stockOutLineId,
                    'product_id' => $productId,
                    'stock_item_id' => $returnStockItemId,
                    'qty' => 1,
                    'reason_for_return' => 'WARRANTY_CLAIM',
                    'condition_on_return' => 'Used',
                    'next_action' => 'REPAIR',
                ],
            ],
        ])->assertCreated();

        $this->getJson('/api/reports/repairs/summary?age_bucket=31_plus')
            ->assertOk()
            ->assertJsonPath('data.0.age_bucket', '31_plus');

        $this->getJson('/api/reports/rts/summary?age_bucket=8_30')
            ->assertOk()
            ->assertJsonPath('data.0.age_bucket', '8_30');

        $this->getJson('/api/reports/customer-returns/summary?age_bucket=0_7')
            ->assertOk()
            ->assertJsonPath('data.0.age_bucket', '0_7');
    }

    /**
     * @return array{int, int, int}
     */
    private function createReceivedDevice(User $admin): array
    {
        Sanctum::actingAs($admin, ['admin-access']);

        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'product_code' => 'DEV-RTS-'.fake()->numerify('######'),
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
}
