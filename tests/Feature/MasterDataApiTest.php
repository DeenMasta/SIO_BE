<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
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
        $supplier = Supplier::factory()->create();
        $staff = User::factory()->staff()->create();
        Sanctum::actingAs($staff, ['staff-access']);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data');

        $this->postJson('/api/products', [
            'product_code' => 'PRD-0001',
            'product_name' => 'Router X',
            'product_type' => 'DEVICE',
            'supplier_id' => $supplier->id,
            'selling_price' => 150,
            'uom' => 'PCS',
            'reorder_level' => 5,
            'status' => 'ACTIVE',
        ])->assertForbidden();
    }

    public function test_admin_can_crud_product(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        Sanctum::actingAs($admin, ['admin-access']);

        $created = $this->postJson('/api/products', [
            'product_code' => 'PRD-1001',
            'product_name' => 'Barcode Scanner',
            'product_type' => 'DEVICE',
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
            ->assertJsonCount(2, 'data.accessories')
            ->assertJsonPath('data.accessories.0.accessory_name', 'Charging Cable')
            ->assertJsonPath('data.accessories.1.accessory_name', 'Power Adapter')
            ->assertJsonCount(3, 'data.conditions')
            ->assertJsonPath('data.conditions.0.condition_name', 'Body Condition');

        $this->patchJson('/api/products/'.$id, [
            'product_name' => 'Barcode Scanner Pro',
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

    public function test_product_create_rejects_unknown_fields_and_duplicate_code(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        Sanctum::actingAs($admin, ['admin-access']);

        Product::factory()->create(['product_code' => 'PRD-2222']);

        $this->postJson('/api/products', [
            'product_code' => 'PRD-2222',
            'product_name' => 'Duplicate Product',
            'product_type' => 'ACCESSORY',
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
}
