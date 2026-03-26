<?php

namespace Tests\Feature;

use App\Domain\ExceptionsReturns\Enums\RepairStatus;
use App\Domain\ExceptionsReturns\Services\RepairStateMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SprintCRepairStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_status_machine_allows_valid_transition_open_to_in_progress(): void
    {
        $oldStatus = RepairStatus::Open;
        $newStatus = RepairStatus::InProgress;

        $this->assertTrue(RepairStateMachine::isAllowed($oldStatus, $newStatus));
    }

    public function test_repair_status_machine_allows_valid_transition_open_to_cancelled(): void
    {
        $oldStatus = RepairStatus::Open;
        $newStatus = RepairStatus::Cancelled;

        $this->assertTrue(RepairStateMachine::isAllowed($oldStatus, $newStatus));
    }

    public function test_repair_status_machine_allows_valid_transition_open_to_completed(): void
    {
        $oldStatus = RepairStatus::Open;
        $newStatus = RepairStatus::Completed;

        $this->assertTrue(RepairStateMachine::isAllowed($oldStatus, $newStatus));
    }

    public function test_repair_status_machine_allows_valid_transition_in_progress_to_completed(): void
    {
        $oldStatus = RepairStatus::InProgress;
        $newStatus = RepairStatus::Completed;

        $this->assertTrue(RepairStateMachine::isAllowed($oldStatus, $newStatus));
    }

    public function test_repair_status_machine_allows_valid_transition_in_progress_to_cancelled(): void
    {
        $oldStatus = RepairStatus::InProgress;
        $newStatus = RepairStatus::Cancelled;

        $this->assertTrue(RepairStateMachine::isAllowed($oldStatus, $newStatus));
    }

    public function test_repair_status_machine_denies_transition_from_completed(): void
    {
        $oldStatus = RepairStatus::Completed;

        $this->assertFalse(RepairStateMachine::isAllowed($oldStatus, RepairStatus::Open));
        $this->assertFalse(RepairStateMachine::isAllowed($oldStatus, RepairStatus::InProgress));
        $this->assertFalse(RepairStateMachine::isAllowed($oldStatus, RepairStatus::Cancelled));
    }

    public function test_repair_status_machine_denies_transition_from_cancelled(): void
    {
        $oldStatus = RepairStatus::Cancelled;

        $this->assertFalse(RepairStateMachine::isAllowed($oldStatus, RepairStatus::Open));
        $this->assertFalse(RepairStateMachine::isAllowed($oldStatus, RepairStatus::InProgress));
        $this->assertFalse(RepairStateMachine::isAllowed($oldStatus, RepairStatus::Completed));
    }

    public function test_repair_status_machine_denies_transition_in_progress_to_open(): void
    {
        $oldStatus = RepairStatus::InProgress;
        $newStatus = RepairStatus::Open;

        $this->assertFalse(RepairStateMachine::isAllowed($oldStatus, $newStatus));
    }

    public function test_repair_status_machine_allows_transitions_from_open(): void
    {
        $allowed = RepairStateMachine::allowedTransitions(RepairStatus::Open);

        $this->assertCount(3, $allowed);
        $this->assertContains(RepairStatus::InProgress, $allowed);
        $this->assertContains(RepairStatus::Cancelled, $allowed);
        $this->assertContains(RepairStatus::Completed, $allowed);
    }

    public function test_repair_status_machine_allows_transitions_from_in_progress(): void
    {
        $allowed = RepairStateMachine::allowedTransitions(RepairStatus::InProgress);

        $this->assertCount(2, $allowed);
        $this->assertContains(RepairStatus::Completed, $allowed);
        $this->assertContains(RepairStatus::Cancelled, $allowed);
    }

    public function test_repair_status_machine_has_no_transitions_from_completed(): void
    {
        $allowed = RepairStateMachine::allowedTransitions(RepairStatus::Completed);

        $this->assertEmpty($allowed);
    }

    public function test_repair_status_machine_has_no_transitions_from_cancelled(): void
    {
        $allowed = RepairStateMachine::allowedTransitions(RepairStatus::Cancelled);

        $this->assertEmpty($allowed);
    }

    public function test_records_status_history_when_repair_status_changes(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-SH-001',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
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

    public function test_tracks_full_status_history_timeline(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-SH-002',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
            'issue_description' => 'Screen broken',
        ])->json('data');

        $repairId = (int) $repair['id'];

        // Open → InProgress
        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'IN_PROGRESS',
            'remarks' => 'Started work',
        ])->assertOk();

        // InProgress → Completed
        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'COMPLETED',
            'remarks' => 'Repair finished',
        ])->assertOk();

        $histories = \App\Models\RepairStatusHistory::query()
            ->where('repair_id', $repairId)
            ->orderBy('changed_at')
            ->get();

        $this->assertCount(2, $histories);
        $this->assertEquals('OPEN', $histories[0]->from_status);
        $this->assertEquals('IN_PROGRESS', $histories[0]->to_status);
        $this->assertEquals('IN_PROGRESS', $histories[1]->from_status);
        $this->assertEquals('COMPLETED', $histories[1]->to_status);
    }

    public function test_rejects_invalid_repair_status_transition(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-SH-003',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
            'issue_description' => 'Battery problem',
        ])->json('data');

        $repairId = (int) $repair['id'];

        // Transition: Open → InProgress
        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'IN_PROGRESS',
        ])->assertOk();

        // Try invalid: InProgress → Open (not allowed)
        $this->patchJson("/api/repairs/{$repairId}/status", [
            'repair_status' => 'OPEN',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('repair_status');
    }

    public function test_marks_item_unavailable_when_repair_is_cancelled(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-SH-004',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
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

    public function test_marks_item_available_when_repair_is_completed(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        $repair = $this->postJson('/api/repairs', [
            'repair_transaction_number' => 'RPR-SH-005',
            'repair_date' => now()->toDateString(),
            'stock_item_id' => $stockItemId,
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
}
