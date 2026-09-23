<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SaleOrder;
use App\Models\SaleOrderLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorrectSaleOrderDispatchedQuantityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_corrects_an_over_ordered_dispatched_line_and_fulfils_the_order(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $device = Product::factory()->create(['product_code' => 'AT-P80']);
        $paper = Product::factory()->create(['product_code' => 'TPR58']);
        $saleOrder = SaleOrder::query()->create([
            'so_number' => 'SO-20260918-0005',
            'so_date' => '2026-09-18',
            'customer_id' => $customer->id,
            'invoice_number' => '002681/09/2026',
            'status' => 'CONFIRMED',
            'created_by' => $admin->id,
        ]);
        $deviceLine = SaleOrderLine::query()->create([
            'sale_order_id' => $saleOrder->id,
            'product_id' => $device->id,
            'ordered_qty' => 2,
            'fulfilled_qty' => 1,
            'unit_price' => 550,
            'subtotal' => 1100,
        ]);
        SaleOrderLine::query()->create([
            'sale_order_id' => $saleOrder->id,
            'product_id' => $paper->id,
            'ordered_qty' => 8,
            'fulfilled_qty' => 8,
            'unit_price' => 2.5,
            'subtotal' => 20,
        ]);

        $this->artisan('sales-orders:correct-dispatched-quantity', [
            'so_number' => $saleOrder->so_number,
            'product_code' => $device->product_code,
            'ordered_qty' => 1,
            '--yes' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('sale_order_lines', [
            'id' => $deviceLine->id,
            'ordered_qty' => 1,
            'fulfilled_qty' => 1,
            'subtotal' => 550,
        ]);
        $this->assertDatabaseHas('sale_orders', [
            'id' => $saleOrder->id,
            'status' => 'FULFILLED',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'entity_name' => 'SaleOrderDispatchedQuantityCorrection',
            'entity_id' => $saleOrder->id,
        ]);
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_it_refuses_to_fulfil_an_order_with_another_unfulfilled_line(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $device = Product::factory()->create(['product_code' => 'AT-P80']);
        $paper = Product::factory()->create(['product_code' => 'TPR58']);
        $saleOrder = SaleOrder::query()->create([
            'so_number' => 'SO-PARTIAL-001',
            'so_date' => '2026-09-18',
            'customer_id' => $customer->id,
            'status' => 'CONFIRMED',
            'created_by' => $admin->id,
        ]);
        $deviceLine = SaleOrderLine::query()->create([
            'sale_order_id' => $saleOrder->id,
            'product_id' => $device->id,
            'ordered_qty' => 2,
            'fulfilled_qty' => 1,
            'unit_price' => 550,
            'subtotal' => 1100,
        ]);
        SaleOrderLine::query()->create([
            'sale_order_id' => $saleOrder->id,
            'product_id' => $paper->id,
            'ordered_qty' => 8,
            'fulfilled_qty' => 7,
            'unit_price' => 2.5,
            'subtotal' => 20,
        ]);

        $this->artisan('sales-orders:correct-dispatched-quantity', [
            'so_number' => $saleOrder->so_number,
            'product_code' => $device->product_code,
            'ordered_qty' => 1,
            '--yes' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseHas('sale_order_lines', [
            'id' => $deviceLine->id,
            'ordered_qty' => 2,
        ]);
        $this->assertDatabaseHas('sale_orders', [
            'id' => $saleOrder->id,
            'status' => 'CONFIRMED',
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
