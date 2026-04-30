<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Domain\MasterData\Enums\RecordStatus;
use App\Domain\MasterData\Enums\ProductType;

class MasterDataSeeder extends Seeder
{
    /**
     * Run the database seeds.sdssdsdssdsdssds
     */
    public function run(): void
    {

        $admin = \App\Models\User::first();
        if (!$admin) {
            \App\Models\User::create([
                'name' => 'Admin Test',
                'email' => 'admin_test@example.com',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
            ]);
            $admin = \App\Models\User::first();
        }

        // Seed 5 Suppliers
        $suppliers = [];
        for ($i = 1; $i <= 5; $i++) {
            $suppliers[] = \App\Models\Supplier::create([
                'supplier_code' => 'SUPP-00' . $i,
                'supplier_name' => 'Supplier ' . chr(64 + $i),
                'contact_person' => 'Person ' . $i,
                'phone' => '0812345678' . $i,
                'email' => 'supplier'.$i.'@example.com',
                'address' => 'Supplier Address ' . $i,
                'status' => RecordStatus::Active,
                'remarks' => 'Seeded Supplier',
            ]);
        }

        // Seed 5 Products linked to the seeded suppliers
        foreach ($suppliers as $index => $supplier) {
            $pid = $index + 1;
            \App\Models\Product::create([
                'product_code' => 'PROD-00' . $pid,
                'product_name' => 'Product ' . chr(64 + $pid),
                'product_type' => ProductType::Device,
                'supplier_id' => $supplier->id,
                'selling_price' => 100000 * $pid,
                'uom' => 'PCS',
                'reorder_level' => 10,
                'remarks' => 'Seeded Product',
                'status' => RecordStatus::Active,
                'created_by' => $admin->id,
            ]);
        }

        // Seed 5 Customers
        for ($i = 1; $i <= 5; $i++) {
            \App\Models\Customer::create([
                'customer_name' => 'Customer ' . chr(64 + $i),
                'contact_person' => 'Cust Person ' . $i,
                'phone' => '0898765432' . $i,
                'email' => 'customer'.$i.'@example.com',
                'address' => 'Customer Address ' . $i,
                'status' => RecordStatus::Active,
                'remarks' => 'Seeded Customer',
            ]);
        }
    }
}
