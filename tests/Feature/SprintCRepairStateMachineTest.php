<?php

namespace Tests\Feature;

use App\Domain\ExceptionsReturns\Enums\RepairFlow;
use App\Domain\ExceptionsReturns\Enums\RepairStatus;
use App\Domain\ExceptionsReturns\Services\RepairStateMachine;
use App\Models\RepairStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SprintCRepairStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_repair_status_machine_allows_open_to_completed(): void
    {
        $this->assertTrue(RepairStateMachine::isAllowed(
            RepairFlow::Internal,
            RepairStatus::Open,
            RepairStatus::Completed,
        ));
    }

    public function test_customer_repair_status_machine_allows_ready_to_return_path(): void
    {
        $this->assertTrue(RepairStateMachine::isAllowed(
            RepairFlow::Customer,
            RepairStatus::InProgress,
            RepairStatus::ReadyToReturn,
        ));

        $this->assertTrue(RepairStateMachine::isAllowed(
            RepairFlow::Customer,
            RepairStatus::ReadyToReturn,
            RepairStatus::ReturnedToCustomer,
        ));
    }

    public function test_customer_repair_status_machine_denies_completed_transition(): void
    {
        $this->assertFalse(RepairStateMachine::isAllowed(
            RepairFlow::Customer,
            RepairStatus::InProgress,
            RepairStatus::Completed,
        ));
    }

    public function test_internal_repair_status_machine_denies_customer_return_transition(): void
    {
        $this->assertFalse(RepairStateMachine::isAllowed(
            RepairFlow::Internal,
            RepairStatus::InProgress,
            RepairStatus::ReadyToReturn,
        ));
    }

    public function test_internal_repair_status_machine_allows_expected_transitions_from_open(): void
    {
        $allowed = RepairStateMachine::allowedTransitions(RepairFlow::Internal, RepairStatus::Open);

        $this->assertCount(3, $allowed);
        $this->assertContains(RepairStatus::InProgress, $allowed);
        $this->assertContains(RepairStatus::Cancelled, $allowed);
        $this->assertContains(RepairStatus::Completed, $allowed);
    }

    public function test_customer_repair_status_machine_allows_expected_transitions_from_ready_to_return(): void
    {
        $allowed = RepairStateMachine::allowedTransitions(RepairFlow::Customer, RepairStatus::ReadyToReturn);

        $this->assertCount(2, $allowed);
        $this->assertContains(RepairStatus::ReturnedToCustomer, $allowed);
        $this->assertContains(RepairStatus::Cancelled, $allowed);
    }

    public function test_records_status_history_when_internal_repair_status_changes(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createInStockDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-SH-001',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
            'repair_flow' => 'INTERNAL',
            'issue_description' => 'Power issue',
        ])->json('data');

        $repairId = (int) $repair['id'];

        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'IN_PROGRESS',
            'remarks' => 'Started diagnosis',
        ])->assertOk();

        $this->assertDatabaseHas('repair_status_histories', [
            'repair_id' => $repairId,
            'from_status' => 'OPEN',
            'to_status' => 'IN_PROGRESS',
            'remarks' => 'Started diagnosis',
        ]);
    }

    public function test_tracks_full_status_history_timeline_for_customer_repair(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId, , $customerId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-SH-002',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
            'repair_flow' => 'CUSTOMER',
            'customer_id' => $customerId,
            'issue_description' => 'Screen broken',
        ])->json('data');

        $repairId = (int) $repair['id'];

        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'IN_PROGRESS',
            'remarks' => 'Started work',
        ])->assertOk();

        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'READY_TO_RETURN',
            'remarks' => 'Repair finished',
        ])->assertOk();

        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'RETURNED_TO_CUSTOMER',
            'returned_to_customer_date' => now()->toDateString(),
            'return_tracking_number' => 'TRACK-12345',
            'remarks' => 'Courier handoff complete',
        ])->assertOk();

        $histories = RepairStatusHistory::query()
            ->where('repair_id', $repairId)
            ->orderBy('changed_at')
            ->get();

        $this->assertCount(3, $histories);
        $this->assertEquals('OPEN', $histories[0]->from_status);
        $this->assertEquals('IN_PROGRESS', $histories[0]->to_status);
        $this->assertEquals('IN_PROGRESS', $histories[1]->from_status);
        $this->assertEquals('READY_TO_RETURN', $histories[1]->to_status);
        $this->assertEquals('READY_TO_RETURN', $histories[2]->from_status);
        $this->assertEquals('RETURNED_TO_CUSTOMER', $histories[2]->to_status);
    }

    public function test_rejects_invalid_repair_status_transition(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createInStockDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-SH-003',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
            'repair_flow' => 'INTERNAL',
            'issue_description' => 'Battery problem',
        ])->json('data');

        $repairId = (int) $repair['id'];

        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'IN_PROGRESS',
        ])->assertOk();

        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'OPEN',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('repair_status');
    }

    public function test_marks_item_unavailable_when_internal_repair_is_cancelled(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createInStockDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-SH-004',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
            'repair_flow' => 'INTERNAL',
            'issue_description' => 'Unrepairable',
        ])->json('data');

        $repairId = (int) $repair['id'];

        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'CANCELLED',
            'remarks' => 'Device unrepairable',
        ])->assertOk();

        $this->assertDatabaseHas('stock_items', [
            'id' => $stockItemId,
            'current_status' => 'IN_STOCK',
            'is_available' => 0,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'reference_table' => 'repairs',
            'reference_id' => $repairId,
            'movement_type' => 'REPAIR_CANCELLED',
        ]);
    }

    public function test_marks_item_available_when_internal_repair_is_completed(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createInStockDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-SH-005',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
            'repair_flow' => 'INTERNAL',
            'issue_description' => 'Fixable issue',
        ])->json('data');

        $repairId = (int) $repair['id'];

        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'COMPLETED',
            'remarks' => 'Repair successful',
        ])->assertOk();

        $this->assertDatabaseHas('stock_items', [
            'id' => $stockItemId,
            'current_status' => 'IN_STOCK',
            'is_available' => 1,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'reference_table' => 'repairs',
            'reference_id' => $repairId,
            'movement_type' => 'REPAIR_OUT',
        ]);
    }

    public function test_customer_repair_requires_return_date_when_returned_to_customer(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId, , $customerId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-SH-006',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
            'repair_flow' => 'CUSTOMER',
            'customer_id' => $customerId,
            'issue_description' => 'Face ID issue',
        ])->json('data');

        $repairId = (int) $repair['id'];

        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'READY_TO_RETURN',
        ])->assertOk();

        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'RETURNED_TO_CUSTOMER',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('returned_to_customer_date');
    }

    public function test_customer_repair_returns_item_to_delivered_status(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId, , $customerId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-SH-007',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
            'repair_flow' => 'CUSTOMER',
            'customer_id' => $customerId,
            'issue_description' => 'Speaker issue',
        ])->json('data');

        $repairId = (int) $repair['id'];

        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'READY_TO_RETURN',
            'remarks' => 'Ready for courier',
        ])->assertOk();

        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'RETURNED_TO_CUSTOMER',
            'returned_to_customer_date' => now()->toDateString(),
            'return_tracking_number' => 'TRACK-67890',
            'remarks' => 'Sent back to customer',
        ])->assertOk();

        $this->assertDatabaseHas('repairs', [
            'id' => $repairId,
            'repair_status' => 'RETURNED_TO_CUSTOMER',
            'return_tracking_number' => 'TRACK-67890',
        ]);

        $this->assertDatabaseHas('stock_items', [
            'id' => $stockItemId,
            'current_status' => 'DELIVERED',
            'is_available' => 0,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'reference_table' => 'repairs',
            'reference_id' => $repairId,
            'movement_type' => 'REPAIR_RETURN_TO_CUSTOMER',
        ]);
    }
}
