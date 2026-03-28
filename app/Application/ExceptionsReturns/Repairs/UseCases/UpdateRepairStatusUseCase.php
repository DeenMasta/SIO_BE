<?php

namespace App\Application\ExceptionsReturns\Repairs\UseCases;

use App\Application\Contracts\Repositories\RepairRepository;
use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Domain\ExceptionsReturns\Enums\RepairStatus;
use App\Domain\ExceptionsReturns\Services\RepairStateMachine;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\Repair;
use App\Models\RepairStatusHistory;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class UpdateRepairStatusUseCase implements UseCase
{
    public function __construct(
        private readonly RepairRepository $repairs,
        private readonly AuditLogger $auditLogger,
    )
    {
    }

    public function execute(mixed $payload = null): Repair
    {
        $data = (array) $payload;
        /** @var Repair $repair */
        $repair = $data['repair'];
        $status = RepairStatus::from((string) $data['repair_status']);

        return DB::transaction(function () use ($repair, $status, $data): Repair {
            // Validate transition using state machine
            RepairStateMachine::validateTransition($repair->repair_status, $status);

            // Save the old status before updating
            $oldStatus = $repair->repair_status;

            $updated = $this->repairs->update($repair, [
                'repair_status' => $status,
                'remarks' => $data['remarks'] ?? $repair->remarks,
            ]);

            // Log status history
            RepairStatusHistory::query()->create([
                'repair_id' => $repair->id,
                'from_status' => $oldStatus?->value,
                'to_status' => $status->value,
                'remarks' => $data['remarks'] ?? null,
                'changed_by' => (int) $data['updated_by'],
                'changed_at' => now(),
            ]);

            // Handle side effects based on new status
            if ($status === RepairStatus::Completed) {
                $this->handleCompletion($repair, $data);
            } elseif ($status === RepairStatus::Cancelled) {
                $this->handleCancellation($repair, $data);
            }

            $this->auditLogger->log(
                userId: (int) $data['updated_by'],
                moduleName: 'ExceptionsReturns',
                entityName: 'Repair',
                entityId: (int) $updated->id,
                action: AuditAction::Update,
                oldValues: ['repair_status' => $oldStatus?->value],
                newValues: ['repair_status' => $updated->repair_status?->value],
            );

            return $updated;
        });
    }

    /**
     * Handle repair completion: Move item back to IN_STOCK status.
     */
    private function handleCompletion(Repair $repair, array $data): void
    {
        $stockItem = StockItem::query()->lockForUpdate()->findOrFail((int) $repair->stock_item_id);
        $stockItem->update([
            'current_status' => StockItemStatus::InStock,
            'is_available' => true,
            'last_movement_at' => now(),
        ]);

        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $stockItem->product_id,
            'stock_item_id' => $stockItem->id,
            'movement_type' => MovementType::RepairOut,
            'reference_table' => 'repairs',
            'reference_id' => $repair->id,
            'qty_in' => 1,
            'qty_out' => 0,
            'from_status' => StockItemStatus::UnderRepair->value,
            'to_status' => StockItemStatus::InStock->value,
            'performed_by' => (int) $data['updated_by'],
            'remarks' => $data['remarks'] ?? null,
        ]);

    }

    /**
     * Handle repair cancellation: Move item back to IN_STOCK status (unrepairable).
     */
    private function handleCancellation(Repair $repair, array $data): void
    {
        $stockItem = StockItem::query()->lockForUpdate()->findOrFail((int) $repair->stock_item_id);
        $stockItem->update([
            'current_status' => StockItemStatus::InStock,
            'is_available' => false, // Mark as unavailable (unrepairable)
            'last_movement_at' => now(),
        ]);

        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $stockItem->product_id,
            'stock_item_id' => $stockItem->id,
            'movement_type' => MovementType::RepairCancelled,
            'reference_table' => 'repairs',
            'reference_id' => $repair->id,
            'qty_in' => 0,
            'qty_out' => 0,
            'from_status' => StockItemStatus::UnderRepair->value,
            'to_status' => StockItemStatus::InStock->value,
            'performed_by' => (int) $data['updated_by'],
            'remarks' => $data['remarks'] ?? 'Repair cancelled',
        ]);

    }
}
