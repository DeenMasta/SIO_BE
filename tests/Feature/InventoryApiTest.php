<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockIn;
use App\Models\StockInLine;
use App\Models\StockItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_list_inventory_stock_for_all_products(): void
    {
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();

        $consumable = Product::factory()->create([
            'product_code' => 'INV-CONS-001',
            'product_name' => 'Consumable Stock',
            'product_type' => 'CONSUMABLE',
            'requires_serial_number' => false,
            'supplier_id' => $supplier->id,
            'reorder_level' => 5,
            'status' => 'ACTIVE',
        ]);

        $device = Product::factory()->create([
            'product_code' => 'INV-DEV-001',
            'product_name' => 'Device Stock',
            'product_type' => 'DEVICE',
            'requires_serial_number' => true,
            'supplier_id' => $supplier->id,
            'reorder_level' => 2,
            'status' => 'ACTIVE',
        ]);

        $emptyProduct = Product::factory()->create([
            'product_code' => 'INV-EMPTY-001',
            'product_name' => 'Empty Stock',
            'product_type' => 'CONSUMABLE',
            'requires_serial_number' => false,
            'supplier_id' => $supplier->id,
            'reorder_level' => 0,
            'status' => 'ACTIVE',
        ]);

        $this->upsertStockBalance($consumable->id, 12);
        $this->upsertStockBalance($device->id, 3);
        $this->createSerializedStock($device, $staff, $supplier, 'SER-PASSED-001', 'PASSED', true);
        $this->createSerializedStock($device, $staff, $supplier, 'SER-PENDING-001', 'PENDING', true);
        $this->createSerializedStock($device, $staff, $supplier, 'SER-DELIVERED-001', 'PASSED', false, 'DELIVERED');

        Sanctum::actingAs($staff, ['staff-access']);

        $response = $this->getJson('/api/inventories?per_page=10')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.pagination.total', 3);

        $records = collect($response->json('data'));

        $this->assertSame(12, $records->firstWhere('product_id', $consumable->id)['qty_available']);
        $this->assertSame('in_stock', $records->firstWhere('product_id', $consumable->id)['stock_status']);

        $this->assertSame(1, $records->firstWhere('product_id', $device->id)['qty_available']);
        $this->assertSame(3, $records->firstWhere('product_id', $device->id)['qty_in_stock']);
        $this->assertSame(0, $records->firstWhere('product_id', $device->id)['qty_internal_use']);
        $this->assertSame('low_stock', $records->firstWhere('product_id', $device->id)['stock_status']);

        $this->assertSame(0, $records->firstWhere('product_id', $emptyProduct->id)['qty_available']);
        $this->assertSame('out_of_stock', $records->firstWhere('product_id', $emptyProduct->id)['stock_status']);
    }

    public function test_staff_can_view_inventory_detail_with_all_registered_serials(): void
    {
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $device = Product::factory()->create([
            'product_code' => 'INV-DEV-DETAIL',
            'product_name' => 'Device Detail',
            'product_type' => 'DEVICE',
            'requires_serial_number' => true,
            'supplier_id' => $supplier->id,
            'reorder_level' => 1,
            'status' => 'ACTIVE',
        ]);

        $this->upsertStockBalance($device->id, 2);
        $this->createSerializedStock($device, $staff, $supplier, 'SER-AVAILABLE-001', 'PASSED', true);
        $this->createSerializedStock($device, $staff, $supplier, 'SER-FAILED-001', 'FAILED', true);
        $this->createSerializedStock($device, $staff, $supplier, 'SER-DELIVERED-001', 'PASSED', false, 'DELIVERED');

        Sanctum::actingAs($staff, ['staff-access']);

        $this->getJson('/api/inventories/'.$device->id)
            ->assertOk()
            ->assertJsonPath('data.inventory.product_id', $device->id)
            ->assertJsonPath('data.inventory.qty_available', 1)
            ->assertJsonPath('data.serials.0.serial_number', 'SER-AVAILABLE-001')
            ->assertJsonPath('meta.serials_pagination.total', 3);
    }

    public function test_staff_can_filter_inventory_detail_serials_by_status(): void
    {
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $device = Product::factory()->create([
            'product_code' => 'INV-DEV-FILTER',
            'product_name' => 'Device Filter',
            'product_type' => 'DEVICE',
            'requires_serial_number' => true,
            'supplier_id' => $supplier->id,
            'reorder_level' => 1,
            'status' => 'ACTIVE',
        ]);

        $this->upsertStockBalance($device->id, 2);
        $this->createSerializedStock($device, $staff, $supplier, 'SER-IN-STOCK-001', 'PASSED', true, 'IN_STOCK');
        $this->createSerializedStock($device, $staff, $supplier, 'SER-DELIVERED-001', 'PASSED', false, 'DELIVERED');

        Sanctum::actingAs($staff, ['staff-access']);

        $this->getJson('/api/inventories/'.$device->id.'?serial_status=DELIVERED')
            ->assertOk()
            ->assertJsonPath('meta.serials_pagination.total', 1)
            ->assertJsonPath('data.serials.0.serial_number', 'SER-DELIVERED-001')
            ->assertJsonPath('data.serials.0.current_status', 'DELIVERED');
    }

    public function test_inventory_endpoints_require_authenticated_active_staff_access(): void
    {
        $this->getJson('/api/inventories')->assertUnauthorized();
    }

    private function upsertStockBalance(int $productId, int $qtyInStock): void
    {
        DB::table('stock_balances')->updateOrInsert(
            ['product_id' => $productId],
            [
                'qty_in_stock' => $qtyInStock,
                'qty_delivered' => 0,
                'qty_internal_use' => 0,
                'qty_under_repair' => 0,
                'qty_returned' => 0,
                'qty_returned_to_supplier' => 0,
                'last_computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function createSerializedStock(
        Product $product,
        User $user,
        Supplier $supplier,
        string $serialNumber,
        string $qcStatus,
        bool $isAvailable,
        string $currentStatus = 'IN_STOCK',
    ): StockItem {
        $stockIn = StockIn::query()->create([
            'stock_in_number' => 'SIN-'.$serialNumber,
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'stock_in_pic_id' => $user->id,
            'status' => 'RECEIVED',
        ]);

        $stockInLine = StockInLine::query()->create([
            'stock_in_id' => $stockIn->id,
            'product_id' => $product->id,
            'received_qty' => 1,
        ]);

        return StockItem::query()->create([
            'product_id' => $product->id,
            'stock_in_line_id' => $stockInLine->id,
            'serial_number' => $serialNumber,
            'serial_source' => 'FACTORY',
            'current_status' => $currentStatus,
            'received_condition' => 'NEW',
            'qc_status' => $qcStatus,
            'is_available' => $isAvailable,
            'last_movement_at' => now(),
        ]);
    }
}
