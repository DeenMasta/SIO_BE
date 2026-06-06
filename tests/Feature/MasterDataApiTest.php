<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Product;
use App\Models\SaleOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MasterDataApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_to_master_data_is_rejected(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
    }

    public function test_staff_can_view_products_but_cannot_create_product(): void
    {
        Product::factory()->count(2)->create();
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create();
        $staff = User::factory()->staff()->create();
        Sanctum::actingAs($staff, ['staff-access']);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.id', $product->id);

        $this->postJson('/api/products', [
            'product_code' => 'PRD-0001',
            'product_name' => 'Router X',
            'product_type' => 'DEVICE',
            'requires_serial_number' => true,
            'supplier_id' => $supplier->id,
            'selling_price' => 150,
            'uom' => 'PCS',
            'reorder_level' => 5,
            'status' => 'ACTIVE',
        ])->assertForbidden();

        $this->patchJson('/api/products/'.$product->id, [
            'product_name' => 'Blocked Product Update',
        ])->assertForbidden();

        $this->deleteJson('/api/products/'.$product->id)->assertForbidden();
    }

    public function test_staff_can_search_products_across_full_dataset_with_server_side_pagination(): void
    {
        $staff = User::factory()->staff()->create();
        Sanctum::actingAs($staff, ['staff-access']);

        Product::factory()->count(20)->create();
        $target = Product::factory()->create([
            'product_code' => 'PRD-NEEDLE-001',
            'product_name' => 'Needle Finder',
            'product_model' => 'NF-1',
        ]);

        $this->getJson('/api/products?per_page=15')
            ->assertOk()
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonPath('meta.pagination.per_page', 15)
            ->assertJsonPath('meta.pagination.total', 21)
            ->assertJsonPath('meta.pagination.last_page', 2);

        $this->getJson('/api/products?per_page=15&q=needle')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.product_code', 'PRD-NEEDLE-001')
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('meta.pagination.last_page', 1);
    }

    public function test_admin_can_crud_product(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        Sanctum::actingAs($admin, ['admin-access']);

        $created = $this->postJson('/api/products', [
            'product_code' => 'PRD-1001',
            'product_name' => 'Barcode Scanner',
            'product_model' => 'BS-9000',
            'product_type' => 'DEVICE',
            'requires_serial_number' => true,
            'supplier_id' => $supplier->id,
            'selling_price' => 450.75,
            'uom' => 'PCS',
            'reorder_level' => 10,
            'status' => 'ACTIVE',
            'accessories' => [
                [
                    'accessory_name' => 'Charging Cable',
                    'quantity' => 1,
                ],
                [
                    'accessory_name' => 'Power Adapter',
                    'quantity' => 1,
                    'remarks' => 'EU plug',
                ],
            ],
            'conditions' => [
                ['condition_name' => 'Body Condition'],
                ['condition_name' => 'Sound Function'],
                ['condition_name' => 'SN Match'],
            ],
        ])->assertCreated();

        $id = (int) $created->json('data.id');

        $created
            ->assertJsonPath('data.supplier_id', $supplier->id)
            ->assertJsonPath('data.supplier.id', $supplier->id)
            ->assertJsonPath('data.product_model', 'BS-9000')
            ->assertJsonPath('data.requires_serial_number', true)
            ->assertJsonCount(2, 'data.accessories')
            ->assertJsonPath('data.accessories.0.accessory_name', 'Charging Cable')
            ->assertJsonPath('data.accessories.1.accessory_name', 'Power Adapter')
            ->assertJsonCount(3, 'data.conditions')
            ->assertJsonPath('data.conditions.0.condition_name', 'Body Condition');

        $this->patchJson('/api/products/'.$id, [
            'product_name' => 'Barcode Scanner Pro',
            'product_model' => 'BS-9500',
            'requires_serial_number' => false,
            'accessories' => [
                [
                    'accessory_name' => 'Charging Dock',
                    'quantity' => 1,
                ],
            ],
            'conditions' => [
                ['condition_name' => 'Connectivity Check'],
                ['condition_name' => 'Power Indicator'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.product_name', 'Barcode Scanner Pro')
            ->assertJsonPath('data.product_model', 'BS-9500')
            ->assertJsonPath('data.requires_serial_number', false)
            ->assertJsonCount(1, 'data.accessories')
            ->assertJsonPath('data.accessories.0.accessory_name', 'Charging Dock')
            ->assertJsonCount(2, 'data.conditions')
            ->assertJsonPath('data.conditions.1.condition_name', 'Power Indicator');

        $this->assertDatabaseHas('product_accessories', [
            'product_id' => $id,
            'accessory_name' => 'Charging Dock',
            'quantity' => 1,
        ]);

        $this->assertDatabaseMissing('product_accessories', [
            'product_id' => $id,
            'accessory_name' => 'Power Adapter',
        ]);

        $this->assertDatabaseHas('product_conditions', [
            'product_id' => $id,
            'condition_name' => 'Connectivity Check',
        ]);

        $this->assertDatabaseMissing('product_conditions', [
            'product_id' => $id,
            'condition_name' => 'SN Match',
        ]);

        $this->deleteJson('/api/products/'.$id)
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    public function test_admin_can_create_product_with_zero_price(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/products', [
            'product_code' => 'PRD-0000',
            'product_name' => 'Zero Price Product',
            'product_model' => 'FREE-0',
            'product_type' => 'CONSUMABLE',
            'requires_serial_number' => false,
            'supplier_id' => $supplier->id,
            'selling_price' => 0,
            'uom' => 'PCS',
            'reorder_level' => 0,
            'status' => 'ACTIVE',
        ])
            ->assertCreated()
            ->assertJsonPath('data.selling_price', '0')
            ->assertJsonPath('data.product_code', 'PRD-0000');

        $this->assertDatabaseHas('products', [
            'product_code' => 'PRD-0000',
            'selling_price' => 0,
        ]);
    }

    public function test_admin_can_create_product_with_decimal_price_string(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/products', [
            'product_code' => 'PRD-0010',
            'product_name' => 'Decimal Price Product',
            'product_model' => 'DEC-10',
            'product_type' => 'CONSUMABLE',
            'requires_serial_number' => false,
            'supplier_id' => $supplier->id,
            'selling_price' => '450.75',
            'uom' => 'PCS',
            'reorder_level' => 1,
            'status' => 'ACTIVE',
        ])
            ->assertCreated()
            ->assertJsonPath('data.selling_price', '450.75');

        $this->assertDatabaseHas('products', [
            'product_code' => 'PRD-0010',
            'selling_price' => 450.75,
        ]);
    }

    public function test_admin_can_update_product_with_decimal_price_string(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'supplier_id' => $supplier->id,
            'selling_price' => 100,
        ]);
        Sanctum::actingAs($admin, ['admin-access']);

        $this->patchJson('/api/products/'.$product->id, [
            'selling_price' => '125.50',
        ])
            ->assertOk()
            ->assertJsonPath('data.selling_price', '125.5');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'selling_price' => 125.50,
        ]);
    }

    public function test_product_create_rejects_unknown_fields_and_duplicate_code(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        Sanctum::actingAs($admin, ['admin-access']);

        Product::factory()->create(['product_code' => 'PRD-2222']);

        $this->postJson('/api/products', [
            'product_code' => 'PRD-2222',
            'product_name' => 'Duplicate Product',
            'product_model' => 'ACC-1',
            'product_type' => 'ACCESSORY',
            'requires_serial_number' => true,
            'supplier_id' => $supplier->id,
            'selling_price' => 9.99,
            'uom' => 'PCS',
            'status' => 'ACTIVE',
            'illegal_field' => 'not-allowed',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['errors']);
    }

    public function test_admin_can_crud_supplier(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['admin-access']);

        $created = $this->postJson('/api/suppliers', [
            'supplier_code' => 'SUP-1001',
            'supplier_name' => 'PT Sumber Elektronik',
            'contact_person' => 'Anita',
            'phone' => '08123456789',
            'email' => 'supplier1001@example.com',
            'address' => 'Jl. Industri 1',
            'status' => 'ACTIVE',
        ])->assertCreated();

        $id = (int) $created->json('data.id');

        $this->putJson('/api/suppliers/'.$id, [
            'supplier_code' => 'SUP-1001',
            'supplier_name' => 'PT Sumber Elektronik Baru',
            'status' => 'ACTIVE',
        ])
            ->assertOk()
            ->assertJsonPath('data.supplier_name', 'PT Sumber Elektronik Baru');

        $this->deleteJson('/api/suppliers/'.$id)->assertOk();
    }

    public function test_admin_can_crud_customer(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['admin-access']);

        $created = $this->postJson('/api/customers', [
            'customer_name' => 'CV Mitra Retail',
            'contact_person' => 'Rudi',
            'phone' => '08111111111',
            'email' => 'customer1001@example.com',
            'address' => 'Jl. Niaga 2',
            'status' => 'ACTIVE',
        ])->assertCreated();

        $id = (int) $created->json('data.id');

        $this->patchJson('/api/customers/'.$id, [
            'customer_name' => 'CV Mitra Retail Utama',
        ])
            ->assertOk()
            ->assertJsonPath('data.customer_name', 'CV Mitra Retail Utama');

        $this->deleteJson('/api/customers/'.$id)->assertOk();
    }

    public function test_staff_can_view_supplier_and_customer(): void
    {
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $customer = Customer::factory()->create();

        Sanctum::actingAs($staff, ['staff-access']);

        $this->getJson('/api/suppliers/'.$supplier->id)->assertOk();
        $this->getJson('/api/customers/'.$customer->id)->assertOk();
    }

    public function test_staff_has_view_only_access_across_master_data_submodules(): void
    {
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create([
            'supplier_id' => $supplier->id,
        ]);
        $package = Package::query()->create([
            'package_code' => 'PKG-STARTER-001',
            'package_name' => 'Starter Bundle',
            'status' => 'ACTIVE',
            'created_by' => User::factory()->admin()->create()->id,
        ]);
        $package->products()->attach($product->id, ['quantity' => 1]);

        Sanctum::actingAs($staff, ['staff-access']);

        $this->getJson('/api/products/'.$product->id)->assertOk();
        $this->getJson('/api/suppliers/'.$supplier->id)->assertOk();
        $this->getJson('/api/customers/'.$customer->id)->assertOk();
        $this->getJson('/api/packages/'.$package->id)->assertOk();

        $this->patchJson('/api/products/'.$product->id, [
            'product_name' => 'Blocked Product Update',
        ])->assertForbidden();

        $this->postJson('/api/suppliers', [
            'supplier_code' => 'SUP-STAFF-001',
            'supplier_name' => 'Blocked Supplier Create',
            'status' => 'ACTIVE',
        ])->assertForbidden();

        $this->patchJson('/api/customers/'.$customer->id, [
            'customer_name' => 'Blocked Customer Update',
        ])->assertForbidden();

        $this->postJson('/api/packages', [
            'package_code' => 'PKG-BLOCKED-001',
            'package_name' => 'Blocked Package Create',
            'status' => 'ACTIVE',
            'products' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ])->assertForbidden();
    }

    public function test_customer_detail_includes_invoice_history_from_sale_orders(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $creator = User::factory()->admin()->create();

        SaleOrder::query()->create([
            'so_number' => 'SO-INV-003',
            'so_date' => '2026-04-03',
            'customer_id' => $customer->id,
            'expected_delivery_date' => '2026-04-10',
            'invoice_number' => 'INV-003',
            'status' => 'FULFILLED',
            'created_by' => $creator->id,
            'remarks' => 'Newest invoice',
        ]);

        SaleOrder::query()->create([
            'so_number' => 'SO-NO-INV',
            'so_date' => '2026-04-02',
            'customer_id' => $customer->id,
            'invoice_number' => null,
            'status' => 'DRAFT',
            'created_by' => $creator->id,
        ]);

        SaleOrder::query()->create([
            'so_number' => 'SO-INV-001',
            'so_date' => '2026-04-01',
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-001',
            'status' => 'CONFIRMED',
            'created_by' => $creator->id,
            'remarks' => 'Older invoice',
        ]);

        SaleOrder::query()->create([
            'so_number' => 'SO-OTHER-001',
            'so_date' => '2026-04-04',
            'customer_id' => $otherCustomer->id,
            'invoice_number' => 'INV-OTHER-001',
            'status' => 'FULFILLED',
            'created_by' => $creator->id,
        ]);

        Sanctum::actingAs($staff, ['staff-access']);

        $response = $this->getJson('/api/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('data.invoice_history.0.invoice_number', 'INV-003')
            ->assertJsonPath('data.invoice_history.0.so_number', 'SO-INV-003')
            ->assertJsonPath('data.invoice_history.1.invoice_number', 'INV-001')
            ->assertJsonMissing(['invoice_number' => 'INV-OTHER-001']);

        $this->assertCount(2, $response->json('data.invoice_history'));
    }
}
