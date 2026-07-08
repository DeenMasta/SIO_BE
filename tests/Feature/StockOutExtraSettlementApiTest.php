<?php

namespace Tests\Feature;

use App\Domain\InventoryCore\Enums\SerialSource;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SaleOrder;
use App\Models\StockIn;
use App\Models\StockInLine;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockOutExtraSettlementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection()->getPdo()->sqliteCreateFunction('GREATEST', static fn (...$values): int|float => max($values), -1);
    }

    public function test_stock_out_can_post_regular_and_extra_lines_in_one_session(): void
    {
        [$admin, $customer, $saleOrder, $saleOrderLineId, $regularProduct, $extraSerializedProduct, $extraNonSerializedProduct, $serializedItemIds] = $this->seedScenario();

        Sanctum::actingAs($admin, ['admin-access']);

        $response = $this->postJson('/api/stock-outs', [
            'sale_order_id' => $saleOrder->id,
            'stock_out_number' => 'SOUT-EXTRA-001',
            'idempotency_key' => 'idem-extra-001',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'lines' => [[
                'product_id' => $regularProduct->id,
                'sale_order_line_id' => $saleOrderLineId,
                'qty' => 2,
            ]],
            'extra_lines' => [
                [
                    'product_id' => $extraSerializedProduct->id,
                    'qty' => 2,
                    'stock_item_ids' => $serializedItemIds,
                    'remarks' => 'Upsell serialized',
                ],
                [
                    'product_id' => $extraNonSerializedProduct->id,
                    'qty' => 3,
                    'remarks' => 'Upsell consumable',
                ],
            ],
        ])->assertCreated();

        $stockOutId = (int) $response->json('data.id');

        $this->assertDatabaseHas('stock_out_lines', [
            'stock_out_id' => $stockOutId,
            'product_id' => $regularProduct->id,
            'sale_order_line_id' => $saleOrderLineId,
            'is_extra' => 0,
        ]);

        $this->assertDatabaseHas('stock_out_lines', [
            'stock_out_id' => $stockOutId,
            'product_id' => $extraSerializedProduct->id,
            'sale_order_line_id' => null,
            'is_extra' => 1,
            'settled_qty' => 0,
        ]);

        $this->assertDatabaseHas('stock_out_lines', [
            'stock_out_id' => $stockOutId,
            'product_id' => $extraNonSerializedProduct->id,
            'sale_order_line_id' => null,
            'is_extra' => 1,
            'settled_qty' => 0,
        ]);

        $this->assertDatabaseHas('sale_order_lines', [
            'id' => $saleOrderLineId,
            'fulfilled_qty' => 2,
        ]);

        $this->assertDatabaseHas('sale_orders', [
            'id' => $saleOrder->id,
            'status' => 'FULFILLED',
        ]);

        foreach ($serializedItemIds as $stockItemId) {
            $this->assertDatabaseHas('stock_items', [
                'id' => $stockItemId,
                'current_status' => 'DELIVERED',
                'is_available' => 0,
            ]);
        }
    }

    public function test_stock_out_extras_can_be_partially_settled_into_sale_order(): void
    {
        [$admin, $customer, $saleOrder, $saleOrderLineId, $regularProduct, $extraSerializedProduct, $extraNonSerializedProduct, $serializedItemIds] = $this->seedScenario();

        Sanctum::actingAs($admin, ['admin-access']);

        $created = $this->postJson('/api/stock-outs', [
            'sale_order_id' => $saleOrder->id,
            'stock_out_number' => 'SOUT-EXTRA-SETTLE-001',
            'idempotency_key' => 'idem-extra-settle-001',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'lines' => [[
                'product_id' => $regularProduct->id,
                'sale_order_line_id' => $saleOrderLineId,
                'qty' => 2,
            ]],
            'extra_lines' => [
                [
                    'product_id' => $extraSerializedProduct->id,
                    'qty' => 2,
                    'stock_item_ids' => $serializedItemIds,
                ],
                [
                    'product_id' => $extraNonSerializedProduct->id,
                    'qty' => 3,
                ],
            ],
        ])->assertCreated();

        $stockOutId = (int) $created->json('data.id');
        $serializedLineId = (int) collect($created->json('data.lines'))->firstWhere('product_id', $extraSerializedProduct->id)['id'];
        $nonSerializedLineId = (int) collect($created->json('data.lines'))->firstWhere('product_id', $extraNonSerializedProduct->id)['id'];

        $settled = $this->postJson('/api/stock-outs/'.$stockOutId.'/settle-extras', [
            'lines' => [
                [
                    'stock_out_line_id' => $serializedLineId,
                    'stock_item_ids' => [$serializedItemIds[0]],
                    'unit_price' => 125.50,
                    'remarks' => 'Paid first serial',
                ],
                [
                    'stock_out_line_id' => $nonSerializedLineId,
                    'settle_qty' => 2,
                    'unit_price' => 15,
                    'remarks' => 'Paid two units',
                ],
            ],
        ])->assertOk();

        $serializedSettledLine = \App\Models\SaleOrderLine::query()
            ->where('sale_order_id', $saleOrder->id)
            ->where('source_stock_out_line_id', $serializedLineId)
            ->firstOrFail();

        $nonSerializedSettledLine = \App\Models\SaleOrderLine::query()
            ->where('sale_order_id', $saleOrder->id)
            ->where('source_stock_out_line_id', $nonSerializedLineId)
            ->firstOrFail();

        $this->assertSame(1, (int) $serializedSettledLine->ordered_qty);
        $this->assertSame(1, (int) $serializedSettledLine->fulfilled_qty);
        $this->assertSame(2, (int) $nonSerializedSettledLine->ordered_qty);
        $this->assertSame(2, (int) $nonSerializedSettledLine->fulfilled_qty);

        $this->assertDatabaseHas('stock_out_lines', [
            'id' => $serializedLineId,
            'settled_qty' => 1,
            'reversed_qty' => 1,
        ]);

        $this->assertDatabaseHas('stock_out_lines', [
            'id' => $nonSerializedLineId,
            'settled_qty' => 2,
            'reversed_qty' => 1,
        ]);

        $this->assertDatabaseHas('stock_out_line_items', [
            'stock_out_line_id' => $serializedLineId,
            'stock_item_id' => $serializedItemIds[0],
            'settled_sale_order_line_id' => $serializedSettledLine->id,
        ]);

        $this->assertDatabaseHas('stock_items', [
            'id' => $serializedItemIds[1],
            'current_status' => 'IN_STOCK',
            'is_available' => 1,
        ]);

        $this->assertDatabaseHas('sale_orders', [
            'id' => $saleOrder->id,
            'status' => 'FULFILLED',
        ]);

        $returnedSerializedLine = collect($settled->json('data.lines'))->firstWhere('id', $serializedLineId);
        $returnedNonSerializedLine = collect($settled->json('data.lines'))->firstWhere('id', $nonSerializedLineId);

        $this->assertSame(0, $returnedSerializedLine['pending_qty']);
        $this->assertSame(1, $returnedSerializedLine['settled_qty']);
        $this->assertSame(1, $returnedSerializedLine['reversed_qty']);
        $this->assertSame(0, $returnedNonSerializedLine['pending_qty']);
        $this->assertSame(2, $returnedNonSerializedLine['settled_qty']);
        $this->assertSame(1, $returnedNonSerializedLine['reversed_qty']);
    }

    public function test_stock_out_pending_extras_can_be_reversed_back_to_stock(): void
    {
        [$admin, $customer, $saleOrder, $saleOrderLineId, $regularProduct, $extraSerializedProduct, $extraNonSerializedProduct, $serializedItemIds] = $this->seedScenario();

        Sanctum::actingAs($admin, ['admin-access']);

        $created = $this->postJson('/api/stock-outs', [
            'sale_order_id' => $saleOrder->id,
            'stock_out_number' => 'SOUT-EXTRA-REVERSE-001',
            'idempotency_key' => 'idem-extra-reverse-001',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'lines' => [[
                'product_id' => $regularProduct->id,
                'sale_order_line_id' => $saleOrderLineId,
                'qty' => 2,
            ]],
            'extra_lines' => [
                [
                    'product_id' => $extraSerializedProduct->id,
                    'qty' => 2,
                    'stock_item_ids' => $serializedItemIds,
                ],
                [
                    'product_id' => $extraNonSerializedProduct->id,
                    'qty' => 3,
                ],
            ],
        ])->assertCreated();

        $stockOutId = (int) $created->json('data.id');
        $serializedLineId = (int) collect($created->json('data.lines'))->firstWhere('product_id', $extraSerializedProduct->id)['id'];
        $nonSerializedLineId = (int) collect($created->json('data.lines'))->firstWhere('product_id', $extraNonSerializedProduct->id)['id'];

        $reversed = $this->postJson('/api/stock-outs/'.$stockOutId.'/reverse-extras', [
            'lines' => [
                [
                    'stock_out_line_id' => $serializedLineId,
                    'stock_item_ids' => [$serializedItemIds[1]],
                    'remarks' => 'Failed upsell serialized',
                ],
                [
                    'stock_out_line_id' => $nonSerializedLineId,
                    'reverse_qty' => 2,
                    'remarks' => 'Failed upsell bulk',
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('stock_out_lines', [
            'id' => $serializedLineId,
            'reversed_qty' => 1,
        ]);

        $this->assertDatabaseHas('stock_out_lines', [
            'id' => $nonSerializedLineId,
            'reversed_qty' => 2,
        ]);

        $this->assertDatabaseHas('stock_items', [
            'id' => $serializedItemIds[1],
            'current_status' => 'IN_STOCK',
            'is_available' => 1,
        ]);

        $this->assertDatabaseHas('stock_out_line_items', [
            'stock_out_line_id' => $serializedLineId,
            'stock_item_id' => $serializedItemIds[1],
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $extraSerializedProduct->id,
            'stock_item_id' => $serializedItemIds[1],
            'movement_type' => 'FAILED_UPSELL_REVERSE',
            'from_status' => 'DELIVERED',
            'to_status' => 'IN_STOCK',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $extraNonSerializedProduct->id,
            'stock_item_id' => null,
            'movement_type' => 'FAILED_UPSELL_REVERSE',
            'qty_in' => 2,
            'from_status' => 'DELIVERED',
            'to_status' => 'IN_STOCK',
        ]);

        $returnedSerializedLine = collect($reversed->json('data.lines'))->firstWhere('id', $serializedLineId);
        $returnedNonSerializedLine = collect($reversed->json('data.lines'))->firstWhere('id', $nonSerializedLineId);

        $this->assertSame(1, $returnedSerializedLine['reversed_qty']);
        $this->assertSame(1, $returnedSerializedLine['pending_qty']);
        $this->assertNotNull(
            collect($returnedSerializedLine['dispatched_items'])->firstWhere('stock_item_id', $serializedItemIds[1])['reversed_at'] ?? null,
        );
        $this->assertSame(2, $returnedNonSerializedLine['reversed_qty']);
        $this->assertSame(1, $returnedNonSerializedLine['pending_qty']);
    }

    /**
     * @return array{User, Customer, SaleOrder, int, Product, Product, Product, array<int, int>}
     */
    private function seedScenario(): array
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();

        $regularProduct = Product::factory()->create([
            'product_type' => 'CONSUMABLE',
            'requires_serial_number' => false,
        ]);

        $extraSerializedProduct = Product::factory()->create([
            'product_type' => 'DEVICE',
            'requires_serial_number' => true,
        ]);

        $extraNonSerializedProduct = Product::factory()->create([
            'product_type' => 'CONSUMABLE',
            'requires_serial_number' => false,
        ]);

        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $regularProduct->id,
            'stock_item_id' => null,
            'movement_type' => 'STOCK_IN',
            'reference_table' => 'test_seed',
            'reference_id' => 5001,
            'qty_in' => 2,
            'qty_out' => 0,
            'to_status' => 'IN_STOCK',
            'performed_by' => $admin->id,
        ]);

        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $extraNonSerializedProduct->id,
            'stock_item_id' => null,
            'movement_type' => 'STOCK_IN',
            'reference_table' => 'test_seed',
            'reference_id' => 5002,
            'qty_in' => 5,
            'qty_out' => 0,
            'to_status' => 'IN_STOCK',
            'performed_by' => $admin->id,
        ]);

        $saleOrder = SaleOrder::query()->create([
            'so_number' => 'SO-EXTRA-001',
            'so_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'status' => 'CONFIRMED',
            'created_by' => $admin->id,
        ]);

        $saleOrderLine = $saleOrder->lines()->create([
            'product_id' => $regularProduct->id,
            'ordered_qty' => 2,
            'fulfilled_qty' => 0,
            'unit_price' => 10,
            'subtotal' => 20,
            'is_free' => false,
        ]);

        $stockIn = StockIn::query()->create([
            'stock_in_number' => 'SIN-EXTRA-'.fake()->numerify('######'),
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'stock_in_pic_id' => $admin->id,
            'status' => 'RECEIVED',
        ]);

        $stockInLine = StockInLine::query()->create([
            'stock_in_id' => $stockIn->id,
            'product_id' => $extraSerializedProduct->id,
            'received_qty' => 2,
        ]);

        $serializedItemIds = [];
        foreach ([1, 2] as $index) {
            $stockItem = StockItem::query()->create([
                'product_id' => $extraSerializedProduct->id,
                'stock_in_line_id' => $stockInLine->id,
                'serial_number' => 'EXTRA-SN-'.$index.'-'.fake()->numerify('####'),
                'serial_source' => SerialSource::Generated,
                'current_status' => StockItemStatus::InStock,
                'received_condition' => 'GOOD',
                'qc_status' => StockItemQcStatus::Passed,
                'is_available' => true,
                'last_movement_at' => now(),
            ]);

            $serializedItemIds[] = (int) $stockItem->id;
        }

        return [
            $admin,
            $customer,
            $saleOrder,
            (int) $saleOrderLine->id,
            $regularProduct,
            $extraSerializedProduct,
            $extraNonSerializedProduct,
            $serializedItemIds,
        ];
    }
}
