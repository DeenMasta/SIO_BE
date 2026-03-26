<?php

namespace App\Application\ExceptionsReturns\Repairs\UseCases;

use App\Application\Contracts\Repositories\RepairRepository;
use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Application\Support\StockBalanceService;
use App\Domain\ExceptionsReturns\Enums\RepairStatus;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\Repair;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateRepairStatusUseCase implements UseCase
{
    public function __construct(
        private readonly RepairRepository $repairs,
        private readonly AuditLogger $auditLogger,
        private readonly StockBalanceService $stockBalances,
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
            $allowed = match ($repair->repair_status) {
                RepairStatus::Open => [RepairStatus::InProgress, RepairStatus::Cancelled, RepairStatus::Completed],
                RepairStatus::InProgress => [RepairStatus::Completed, RepairStatus::Cancelled],
                default => [],
            };

            if (! in_array($status, $allowed, true)) {
                throw ValidationException::withMessages([
                    'repair_status' => ['Invalid repair status transition.'],
                ]);
            }

            $updated = $this->repairs->update($repair, [
                'repair_status' => $status,
                'remarks' => $data['remarks'] ?? $repair->remarks,
            ]);

            if ($status === RepairStatus::Completed) {
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

                $this->stockBalances->transferStatus($stockItem->product_id, StockItemStatus::UnderRepair, StockItemStatus::InStock, 1);
            }

            $this->auditLogger->log(
                userId: (int) $data['updated_by'],
                moduleName: 'ExceptionsReturns',
                entityName: 'Repair',
                entityId: (int) $updated->id,
                action: AuditAction::Update,
                oldValues: ['repair_status' => $repair->repair_status?->value],
                newValues: ['repair_status' => $updated->repair_status?->value],
            );

            return $updated;
        });
    }
}
