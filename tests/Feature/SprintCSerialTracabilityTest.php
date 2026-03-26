<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SprintCSerialTracabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_serial_trace_endpoint_returns_not_found_for_missing_serial(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['admin-access']);

        $this->getJson('/api/reports/serial-trace?serial_number=NONEXISTENT-SERIAL')
            ->assertNotFound()
            ->assertJsonPath('message', 'Serial number not found in inventory.');
    }

    public function test_serial_trace_endpoint_requires_serial_number(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['admin-access']);

        $this->getJson('/api/reports/serial-trace')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Serial number is required.');
    }

    public function test_serial_trace_returns_full_lifecycle_receive_to_qc_to_out(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId, $productId, $customerId, $stockOutId, $stockOutLineId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        // Get the serial number from stock_item
        $serialNumber = \App\Models\StockItem::find($stockItemId)->serial_number;

        $response = $this->getJson("/api/reports/serial-trace?serial_number={$serialNumber}")
            ->assertOk();

        $data = $response->json('data');

        // Verify serial and product data
        $this->assertEquals($serialNumber, $data['serial_number']);
        $this->assertEquals($productId, $data['product']['id']);
        $this->assertNotEmpty($data['product']['product_code']);
        $this->assertEquals('DELIVERED', $data['current_status']); // After stock out, status is DELIVERED

        // Verify movements exist and are in chronological order
        $movements = $data['movements'];
        $this->assertNotEmpty($movements);

        // Should have at least: STOCK_IN, QC_PASS, STOCK_OUT
        $movementTypes = array_column($movements, 'movement_type');
        $this->assertContains('STOCK_IN', $movementTypes);
        $this->assertContains('QC_PASS', $movementTypes);
        $this->assertContains('STOCK_OUT', $movementTypes);

        // Verify chronological order
        $prevTime = 0;
        foreach ($movements as $movement) {
            $currentTime = strtotime($movement['movement_datetime']);
            $this->assertGreaterThanOrEqual($prevTime, $currentTime, 'Movements should be in chronological order (ascending)');
            $prevTime = $currentTime;
        }

        // Verify each movement has required fields
        foreach ($movements as $movement) {
            $this->assertArrayHasKey('id', $movement);
            $this->assertArrayHasKey('movement_datetime', $movement);
            $this->assertArrayHasKey('movement_type', $movement);
            $this->assertArrayHasKey('from_status', $movement);
            $this->assertArrayHasKey('to_status', $movement);
            $this->assertArrayHasKey('reference_table', $movement);
            $this->assertArrayHasKey('reference_id', $movement);
        }
    }

    public function test_serial_trace_for_repair_path(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId, $productId, $customerId, $stockOutId, $stockOutLineId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        // Now create a repair for this device
        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-TRB-001',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
            'issue_description' => 'Device not turning on',
        ])->assertCreated();

        $repairId = (int) $repair->json('data.id');

        // Complete the repair
        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'COMPLETED',
            'remarks' => 'Fixed power supply',
        ])->assertOk();

        // Get serial trace
        $serialNumber = \App\Models\StockItem::find($stockItemId)->serial_number;
        $response = $this->getJson("/api/reports/serial-trace?serial_number={$serialNumber}")
            ->assertOk();

        $data = $response->json('data');

        // Should include repair movements
        $movementTypes = array_column($data['movements'], 'movement_type');
        $this->assertContains('REPAIR_IN', $movementTypes);
        $this->assertContains('REPAIR_OUT', $movementTypes);

        // Final status should be IN_STOCK (from completed repair)
        $this->assertEquals('IN_STOCK', $data['current_status']);
    }

    public function test_serial_trace_for_cancelled_repair(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        // Create a repair and cancel it
        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-TRB-002',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
            'issue_description' => 'Unrepairable damage',
        ])->assertCreated();

        $repairId = (int) $repair->json('data.id');

        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'CANCELLED',
            'remarks' => 'Device unrepairable',
        ])->assertOk();

        // Get serial trace
        $serialNumber = \App\Models\StockItem::find($stockItemId)->serial_number;
        $response = $this->getJson("/api/reports/serial-trace?serial_number={$serialNumber}")
            ->assertOk();

        $data = $response->json('data');

        // Should show cancelled movement
        $movementTypes = array_column($data['movements'], 'movement_type');
        $this->assertContains('REPAIR_CANCELLED', $movementTypes);

        // Item should be unavailable after cancellation
        $this->assertFalse($data['is_available']);
    }

    public function test_serial_trace_staff_can_access(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        $serialNumber = \App\Models\StockItem::find($stockItemId)->serial_number;

        Sanctum::actingAs($staff, ['staff-access']);

        $this->getJson("/api/reports/serial-trace?serial_number={$serialNumber}")
            ->assertOk();
    }
}
