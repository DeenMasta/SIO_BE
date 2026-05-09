<?php

namespace Tests;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Helper method to create a fully delivered device in the inventory system.
     * Runs through: Stock In → QC Pass → Stock Out
     * Returns: [stockItemId, productId, customerId, stockOutId, stockOutLineId]
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
        $stockInLineId = (int) $stockIn->json('data.lines.0.id');
        $stockItemId = (int) $stockIn->json('data.lines.0.stock_items.0.id');

        $this->postJson('/api/qc-transactions', [
            'qc_reference_number' => 'QC-RET-'.fake()->numerify('######'),
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

        $stockOut = $this->postJson('/api/stock-outs', [
            'stock_out_number' => 'SOUT-RET-'.fake()->numerify('######'),
            'idempotency_key' => 'idem-ret-'.fake()->numerify('######'),
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-CR-100001',
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

    /**
     * Helper method to create a QC-passed in-stock device.
     * Runs through: Stock In -> QC Pass
     * Returns: [stockItemId, productId, supplierId]
     *
     * @return array{int, int, int}
     */
    protected function createInStockDevice(User $admin): array
    {
        Sanctum::actingAs($admin, ['admin-access']);

        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'product_code' => 'DEV-INS-'.fake()->numerify('######'),
            'product_type' => 'DEVICE',
        ]);

        $stockIn = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-INS-'.fake()->numerify('######'),
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_qty' => 1,
                    'serial_numbers' => ['SN-INS-'.fake()->numerify('######')],
                ],
            ],
        ])->assertCreated();

        $stockInId = (int) $stockIn->json('data.id');
        $stockItemId = (int) $stockIn->json('data.lines.0.stock_items.0.id');

        $this->postJson('/api/qc-documents', [
            'document_number' => 'QC-INS-'.fake()->numerify('######'),
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

        return [$stockItemId, (int) $product->id, (int) $supplier->id];
    }
}
