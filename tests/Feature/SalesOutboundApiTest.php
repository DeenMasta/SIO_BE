<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesOutboundApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_sale_order_with_free_line(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $response = $this->postJson('/api/sale-orders', [
            'so_number' => 'SO-100001',
            'so_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-SO-100001',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'ordered_qty' => 2,
                    'is_free' => true,
                    'unit_price' => 99.99,
                    'remarks' => 'Promo item',
                ],
            ],
        ])->assertCreated();

        $lineId = (int) $response->json('data.lines.0.id');

        $response
            ->assertJsonPath('data.lines.0.is_free', true)
            ->assertJsonPath('data.lines.0.unit_price', '0.00')
            ->assertJsonPath('data.lines.0.subtotal', '0.00');

        $this->assertDatabaseHas('sale_order_lines', [
            'id' => $lineId,
            'is_free' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
        ]);
    }

    public function test_sale_order_update_replaces_lines_and_keeps_free_line_zero_priced(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $productOne = Product::factory()->create(['product_type' => 'CONSUMABLE']);
        $productTwo = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $created = $this->postJson('/api/sale-orders', [
            'so_number' => 'SO-100002',
            'so_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-SO-100002',
            'lines' => [
                [
                    'product_id' => $productOne->id,
                    'ordered_qty' => 1,
                    'unit_price' => 10,
                ],
            ],
        ])->assertCreated();

        $saleOrderId = (int) $created->json('data.id');

        $this->patchJson('/api/sale-orders/'.$saleOrderId, [
            'so_date' => now()->addDay()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-SO-100002',
            'remarks' => 'Updated promo order',
            'lines' => [
                [
                    'product_id' => $productTwo->id,
                    'ordered_qty' => 3,
                    'is_free' => true,
                    'unit_price' => 15.5,
                    'remarks' => 'Bundle freebie',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.remarks', 'Updated promo order')
            ->assertJsonPath('data.lines.0.product_id', $productTwo->id)
            ->assertJsonPath('data.lines.0.is_free', true)
            ->assertJsonPath('data.lines.0.unit_price', '0.00')
            ->assertJsonPath('data.lines.0.subtotal', '0.00');

        $this->assertDatabaseHas('sale_order_lines', [
            'sale_order_id' => $saleOrderId,
            'product_id' => $productTwo->id,
            'ordered_qty' => 3,
            'is_free' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
        ]);

        $this->assertDatabaseMissing('sale_order_lines', [
            'sale_order_id' => $saleOrderId,
            'product_id' => $productOne->id,
        ]);
    }

    public function test_free_sale_order_line_can_be_fulfilled_through_stock_out(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create([
            'product_code' => 'FREE-STOCK-1001',
            'product_type' => 'CONSUMABLE',
        ]);

        Sanctum::actingAs($admin, ['admin-access']);

        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $product->id,
            'stock_item_id' => null,
            'movement_type' => 'STOCK_IN',
            'reference_table' => 'test_seed',
            'reference_id' => 1001,
            'qty_in' => 2,
            'qty_out' => 0,
            'to_status' => 'IN_STOCK',
            'performed_by' => $admin->id,
        ]);

        $saleOrder = $this->postJson('/api/sale-orders', [
            'so_number' => 'SO-100003',
            'so_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-SO-100003',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'ordered_qty' => 2,
                    'is_free' => true,
                    'unit_price' => 0,
                ],
            ],
        ])->assertCreated();

        $saleOrderId = (int) $saleOrder->json('data.id');
        $saleOrderLineId = (int) $saleOrder->json('data.lines.0.id');

        $this->patchJson('/api/sale-orders/'.$saleOrderId.'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED');

        $this->postJson('/api/stock-outs', [
            'sale_order_id' => $saleOrderId,
            'stock_out_number' => 'SOUT-SO-100003',
            'idempotency_key' => 'idem-so-100003',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'sale_order_line_id' => $saleOrderLineId,
                    'qty' => 2,
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('sale_orders', [
            'id' => $saleOrderId,
            'status' => 'FULFILLED',
        ]);
        $this->assertDatabaseHas('sale_order_lines', [
            'id' => $saleOrderLineId,
            'fulfilled_qty' => 2,
            'is_free' => 1,
        ]);
    }

    public function test_sale_order_export_returns_filtered_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create([
            'customer_name' => 'Export Customer',
        ]);
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/sale-orders', [
            'so_number' => 'SO-EXPORT-001',
            'so_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-EXPORT-001',
            'remarks' => 'Priority delivery',
            'lines' => [[
                'product_id' => $product->id,
                'ordered_qty' => 4,
                'unit_price' => 25,
            ]],
        ])->assertCreated();

        $response = $this->get('/api/sale-orders/export?status=DRAFT&q=SO-EXPORT&format=csv');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('so_number', $content);
        $this->assertStringContainsString('SO-EXPORT-001', $content);
        $this->assertStringContainsString('Export Customer', $content);
        $this->assertStringContainsString('100.00', $content);
    }

    public function test_sale_order_index_returns_customer_name_and_supports_search_by_customer_name_or_so_number(): void
    {
        $admin = User::factory()->admin()->create();
        $matchingCustomer = Customer::factory()->create([
            'customer_name' => 'Acme Industries',
        ]);
        $otherCustomer = Customer::factory()->create([
            'customer_name' => 'Other Customer',
        ]);
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/sale-orders', [
            'so_number' => 'SO-SEARCH-001',
            'so_date' => now()->toDateString(),
            'customer_id' => $matchingCustomer->id,
            'invoice_number' => 'INV-SEARCH-001',
            'lines' => [[
                'product_id' => $product->id,
                'ordered_qty' => 1,
                'unit_price' => 10,
            ]],
        ])->assertCreated();

        $this->postJson('/api/sale-orders', [
            'so_number' => 'SO-OTHER-002',
            'so_date' => now()->toDateString(),
            'customer_id' => $otherCustomer->id,
            'invoice_number' => 'INV-OTHER-002',
            'lines' => [[
                'product_id' => $product->id,
                'ordered_qty' => 1,
                'unit_price' => 10,
            ]],
        ])->assertCreated();

        $this->getJson('/api/sale-orders?q=Acme')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_name', 'Acme Industries')
            ->assertJsonPath('data.0.so_number', 'SO-SEARCH-001');

        $this->getJson('/api/sale-orders?q=SO-SEARCH-001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_name', 'Acme Industries')
            ->assertJsonPath('data.0.so_number', 'SO-SEARCH-001');
    }
}
