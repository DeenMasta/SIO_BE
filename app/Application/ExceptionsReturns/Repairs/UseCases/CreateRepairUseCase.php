<?php

namespace App\Application\ExceptionsReturns\Repairs\UseCases;

use App\Application\Contracts\Repositories\RepairRepository;
use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Domain\ExceptionsReturns\Enums\RepairStatus;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\Repair;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateRepairUseCase implements UseCase
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

        return DB::transaction(function () use ($data): Repair {
            $stockItem = StockItem::query()->lockForUpdate()->findOrFail((int) $data['stock_item_id']);

            if (! in_array($stockItem->current_status, [StockItemStatus::Delivered, StockItemStatus::InStock, StockItemStatus::Returned], true)) {
                throw ValidationException::withMessages([
                    'stock_item_id' => ['Stock item is not eligible for repair.'],
                ]);
            }

            $fromStatus = $stockItem->current_status;

            $repair = $this->repairs->create([
                'repair_transaction_number' => $data['repair_transaction_number'],
                'repair_date' => $data['repair_date'],
                'stock_item_id' => $stockItem->id,
                'customer_id' => $data['customer_id'] ?? null,
                'issue_description' => $data['issue_description'],
                'repair_status' => RepairStatus::Open,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $data['created_by'],
            ]);

            $stockItem->update([
                'current_status' => StockItemStatus::UnderRepair,
                'is_available' => false,
                'last_movement_at' => now(),
            ]);

            StockMovement::query()->create([
                'movement_datetime' => now(),
                'product_id' => $stockItem->product_id,
                'stock_item_id' => $stockItem->id,
                'movement_type' => MovementType::RepairIn,
                'reference_table' => 'repairs',
                'reference_id' => $repair->id,
                'qty_in' => 0,
                'qty_out' => 1,
                'from_status' => $fromStatus->value,
                'to_status' => StockItemStatus::UnderRepair->value,
                'performed_by' => (int) $data['created_by'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->auditLogger->log(
                userId: (int) $data['created_by'],
                moduleName: 'ExceptionsReturns',
                entityName: 'Repair',
                entityId: (int) $repair->id,
                action: AuditAction::Create,
                newValues: ['repair_transaction_number' => $repair->repair_transaction_number, 'repair_status' => $repair->repair_status?->value],
            );

            return $repair;
        });
    }
}
