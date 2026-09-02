<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SaleOrder;
use App\Models\SaleOrderLine;
use App\Models\StockIn;
use App\Models\StockInLine;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StockOut;
use App\Models\StockOutLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplaceDispatchedProductAndRemoveWrongInboundCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_replaces_a_non_serialized_dispatch_with_a_serialized_item_and_removes_the_wrong_inbound_records(): void
    {
        $operator = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $customer = Customer::factory()->create();
        $wrongProduct = Product::factory()->create([
            'supplier_id' => $supplier->id,
            'product_type' => 'CONSUMABLE',
            'requires_serial_number' => false,
        ]);
        $replacementProduct = Product::factory()->create([
            'supplier_id' => $supplier->id,
            'product_type' => 'DEVICE',
            'requires_serial_number' => true,
        ]);

        $purchaseOrder = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $operator->id,
            'status' => 'PARTIAL',
        ]);
        $wrongPurchaseOrderLine = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $wrongProduct->id,
            'ordered_qty' => 1,
            'received_qty' => 1,
            'unit_price' => 120,
            'subtotal' => 120,
        ]);
        PurchaseOrderLine::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $replacementProduct->id,
            'ordered_qty' => 1,
            'received_qty' => 1,
            'unit_price' => 200,
            'subtotal' => 200,
        ]);

        $wrongStockIn = StockIn::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'supplier_id' => $supplier->id,
            'stock_in_pic_id' => $operator->id,
        ]);
        $wrongStockInLine = StockInLine::query()->create([
            'stock_in_id' => $wrongStockIn->id,
            'purchase_order_line_id' => $wrongPurchaseOrderLine->id,
            'product_id' => $wrongProduct->id,
            'received_qty' => 1,
        ]);

        $replacementStockIn = StockIn::factory()->create([
            'supplier_id' => $supplier->id,
            'stock_in_pic_id' => $operator->id,
        ]);
        $replacementStockInLine = StockInLine::query()->create([
            'stock_in_id' => $replacementStockIn->id,
            'product_id' => $replacementProduct->id,
            'received_qty' => 1,
        ]);
        $replacementStockItem = StockItem::query()->create([
            'product_id' => $replacementProduct->id,
            'stock_in_line_id' => $replacementStockInLine->id,
            'serial_number' => 'CMD-REPLACEMENT-001',
            'serial_source' => 'FACTORY',
            'current_status' => 'IN_STOCK',
            'qc_status' => 'PASSED',
            'is_available' => true,
            'last_movement_at' => now(),
        ]);

        $saleOrder = SaleOrder::query()->create([
            'so_number' => 'SO-CMD-REPLACE-001',
            'so_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'status' => 'FULFILLED',
            'created_by' => $operator->id,
        ]);
        $saleOrderLine = SaleOrderLine::query()->create([
            'sale_order_id' => $saleOrder->id,
            'line_type' => 'SALE',
            'product_id' => $wrongProduct->id,
            'ordered_qty' => 1,
            'fulfilled_qty' => 1,
            'is_free' => false,
            'unit_price' => 120,
            'subtotal' => 120,
        ]);
        $stockOut = StockOut::query()->create([
            'sale_order_id' => $saleOrder->id,
            'stock_out_number' => 'SOUT-CMD-REPLACE-001',
            'idempotency_key' => 'cmd-replace-001',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-CMD-REPLACE-001',
            'pic_id' => $operator->id,
            'status' => 'POSTED',
        ]);
        $stockOutLine = StockOutLine::query()->create([
            'stock_out_id' => $stockOut->id,
            'sale_order_line_id' => $saleOrderLine->id,
            'product_id' => $wrongProduct->id,
            'qty' => 1,
            'is_extra' => false,
            'settled_qty' => 0,
            'reversed_qty' => 0,
        ]);
        $inboundMovement = StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $wrongProduct->id,
            'movement_type' => 'STOCK_IN',
            'reference_table' => 'stock_in_lines',
            'reference_id' => $wrongStockInLine->id,
            'qty_in' => 1,
            'qty_out' => 0,
            'to_status' => 'IN_STOCK',
            'performed_by' => $operator->id,
        ]);
        $outboundMovement = StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $wrongProduct->id,
            'movement_type' => 'STOCK_OUT',
            'reference_table' => 'stock_out_lines',
            'reference_id' => $stockOutLine->id,
            'qty_in' => 0,
            'qty_out' => 1,
            'from_status' => 'IN_STOCK',
            'to_status' => 'DELIVERED',
            'performed_by' => $operator->id,
        ]);

        $this->artisan('inventory:replace-dispatch-and-remove-wrong-inbound', [
            'sale_order_line_id' => $saleOrderLine->id,
            'stock_out_line_id' => $stockOutLine->id,
            'wrong_stock_in_line_id' => $wrongStockInLine->id,
            'wrong_purchase_order_line_id' => $wrongPurchaseOrderLine->id,
            'replacement_stock_item_id' => $replacementStockItem->id,
            '--performed-by' => $operator->id,
            '--yes' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('sale_order_lines', [
            'id' => $saleOrderLine->id,
            'product_id' => $replacementProduct->id,
        ]);
        $this->assertDatabaseHas('stock_out_lines', [
            'id' => $stockOutLine->id,
            'product_id' => $replacementProduct->id,
        ]);
        $this->assertDatabaseHas('stock_out_line_items', [
            'stock_out_line_id' => $stockOutLine->id,
            'stock_item_id' => $replacementStockItem->id,
        ]);
        $this->assertDatabaseHas('stock_items', [
            'id' => $replacementStockItem->id,
            'current_status' => 'DELIVERED',
            'is_available' => 0,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'id' => $outboundMovement->id,
            'product_id' => $replacementProduct->id,
            'stock_item_id' => $replacementStockItem->id,
            'reference_table' => 'stock_out_line_items',
        ]);
        $this->assertDatabaseMissing('stock_movements', ['id' => $inboundMovement->id]);
        $this->assertDatabaseMissing('stock_in_lines', ['id' => $wrongStockInLine->id]);
        $this->assertDatabaseMissing('purchase_order_lines', ['id' => $wrongPurchaseOrderLine->id]);
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $purchaseOrder->id,
            'status' => 'COMPLETED',
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $wrongProduct->id,
            'qty_in_stock' => 0,
            'qty_delivered' => 0,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $replacementProduct->id,
            'qty_in_stock' => 0,
            'qty_delivered' => 1,
        ]);
    }
}
