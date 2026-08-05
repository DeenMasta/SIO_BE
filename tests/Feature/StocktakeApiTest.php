<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StocktakeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_short_stocktake_requires_an_audited_write_off_before_inventory_changes(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['product_code' => 'ACC-STK-001', 'product_type' => 'ACCESSORY', 'requires_serial_number' => false]);
        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/stock-ins', ['stock_in_number' => 'SIN-STK-001', 'stock_in_date' => now()->toDateString(), 'supplier_id' => $supplier->id, 'lines' => [['product_id' => $product->id, 'received_qty' => 5]]])->assertCreated();
        $stocktake = $this->postJson('/api/stocktakes', ['stocktake_date' => now()->toDateString()])->assertCreated();
        $stocktakeId = (int) $stocktake->json('data.id');
        $lineId = (int) $stocktake->json('data.lines.0.id');

        $this->postJson("/api/stocktakes/{$stocktakeId}/submit", ['lines' => [['line_id' => $lineId, 'counted_qty' => 3]]])
            ->assertOk()->assertJsonPath('data.status', 'SUBMITTED');

        $report = $this->getJson('/api/missing-item-reports?status=OPEN')->assertOk()->json('data.0');
        $this->assertSame(2, $report['missing_qty']);
        $this->getJson('/api/inventories')->assertOk()->assertJsonPath('data.0.qty_available', 5);

        $this->patchJson('/api/missing-item-reports/'.$report['id'].'/resolve', ['resolution_type' => 'WRITE_OFF', 'resolution_notes' => 'Count rechecked; no dispatch record exists.'])->assertOk();
        $this->getJson('/api/inventories')->assertOk()->assertJsonPath('data.0.qty_available', 3);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'movement_type' => 'STOCKTAKE_WRITE_OFF', 'qty_out' => 2, 'to_status' => 'MISSING']);
    }

    public function test_serialized_shortage_creates_one_report_for_each_unchecked_serial(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['product_code' => 'DEV-STK-001', 'product_type' => 'DEVICE']);
        Sanctum::actingAs($admin, ['admin-access']);
        $stockIn = $this->postJson('/api/stock-ins', ['stock_in_number' => 'SIN-STK-SER-001', 'stock_in_date' => now()->toDateString(), 'supplier_id' => $supplier->id, 'lines' => [['product_id' => $product->id, 'received_qty' => 1, 'serial_numbers' => ['STK-SERIAL-001']]]])->assertCreated();
        $line = $stockIn->json('data.lines.0');
        $stockItemId = (int) $line['stock_items'][0]['id'];
        $this->postJson('/api/qc-transactions', ['qc_reference_number' => 'QC-STK-SER-001', 'stock_in_id' => $stockIn->json('data.id'), 'qc_date' => now()->toDateString(), 'lines' => [['stock_in_line_id' => $line['id'], 'product_id' => $product->id, 'qc_result' => 'PASS', 'stock_item_ids' => [$stockItemId]]]])->assertCreated();

        $stocktake = $this->postJson('/api/stocktakes', ['stocktake_date' => now()->toDateString()])->assertCreated();
        $lineId = (int) $stocktake->json('data.lines.0.id');
        $this->postJson('/api/stocktakes/'.$stocktake->json('data.id').'/submit', ['lines' => [['line_id' => $lineId, 'counted_qty' => 0, 'counted_stock_item_ids' => []]]])->assertOk();
        $this->assertDatabaseHas('missing_item_reports', ['product_id' => $product->id, 'stock_item_id' => $stockItemId, 'missing_qty' => 1, 'status' => 'OPEN']);
    }
}
