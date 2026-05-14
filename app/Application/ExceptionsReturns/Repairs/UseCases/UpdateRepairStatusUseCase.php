<?php

namespace App\Application\ExceptionsReturns\Repairs\UseCases;

use App\Application\Contracts\Repositories\RepairRepository;
use App\Application\Contracts\UseCase;
use App\Application\Inventory\LowStockAlertService;
use App\Application\Support\AuditLogger;
use App\Application\Support\StockBalanceUpdater;
use App\Application\Support\UserNotificationService;
use App\Domain\ExceptionsReturns\Enums\RepairFlow;
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
        private readonly StockBalanceUpdater $stockBalanceUpdater,
        private readonly LowStockAlertService $lowStockAlertService,
        private readonly UserNotificationService $userNotificationService,
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
            $stockItem = StockItem::query()->findOrFail((int) $repair->stock_item_id);
            $affectedProductIds = [(int) $stockItem->product_id];
            $beforeLowStockSnapshot = $this->lowStockAlertService->snapshotForProducts($affectedProductIds);
            RepairStateMachine::validateTransition($repair->repair_flow, $repair->repair_status, $status);
            $this->validateStatusPayload($repair->repair_flow, $status, $data);

            $oldStatus = $repair->repair_status;

            $updated = $this->repairs->update($repair, [
                'repair_status' => $status,
                'returned_to_customer_date' => $status === RepairStatus::ReturnedToCustomer
                    ? $data['returned_to_customer_date']
                    : $repair->returned_to_customer_date,
                'return_tracking_number' => array_key_exists('return_tracking_number', $data)
                    ? $data['return_tracking_number']
                    : $repair->return_tracking_number,
                'remarks' => $data['remarks'] ?? $repair->remarks,
            ]);

            RepairStatusHistory::query()->create([
                'repair_id' => $repair->id,
                'from_status' => $oldStatus?->value,
                'to_status' => $status->value,
                'remarks' => $data['remarks'] ?? null,
                'changed_by' => (int) $data['updated_by'],
                'changed_at' => now(),
            ]);

            if ($status === RepairStatus::Completed) {
                $this->handleCompletion($repair, $data);
            } elseif ($status === RepairStatus::ReturnedToCustomer) {
                $this->handleReturnToCustomer($repair, $data);
            } elseif ($status === RepairStatus::Cancelled) {
                $this->handleCancellation($repair, $data);
            }

            $this->stockBalanceUpdater->recomputeForProducts($affectedProductIds);
            $this->lowStockAlertService->notifyStatusTransitions(
                $beforeLowStockSnapshot,
                $affectedProductIds,
                (int) $data['updated_by'],
            );

            $this->auditLogger->log(
                userId: (int) $data['updated_by'],
                moduleName: 'ExceptionsReturns',
                entityName: 'Repair',
                entityId: (int) $updated->id,
                action: AuditAction::Update,
                oldValues: ['repair_status' => $oldStatus?->value],
                newValues: [
                    'repair_status' => $updated->repair_status?->value,
                    'returned_to_customer_date' => $updated->returned_to_customer_date?->toDateString(),
                    'return_tracking_number' => $updated->return_tracking_number,
                ],
            );

            $this->userNotificationService->notifyAllActiveUsers(
                eventType: 'repair.status-changed',
                title: 'Repair status updated',
                message: sprintf('Repair %s moved from %s to %s.', $updated->repair_transaction_number, $oldStatus?->value ?? 'UNKNOWN', $updated->repair_status?->value ?? 'UNKNOWN'),
                data: [
                    'repair_id' => (int) $updated->id,
                    'repair_transaction_number' => $updated->repair_transaction_number,
                    'from_status' => $oldStatus?->value,
                    'to_status' => $updated->repair_status?->value,
                ],
                exceptUserId: (int) $data['updated_by'],
                level: in_array($status, [RepairStatus::Cancelled], true) ? 'warning' : 'info',
            );

            return $updated;
        });
    }

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

    private function handleCancellation(Repair $repair, array $data): void
    {
        $stockItem = StockItem::query()->lockForUpdate()->findOrFail((int) $repair->stock_item_id);
        $targetStatus = $repair->repair_flow === RepairFlow::Customer
            ? StockItemStatus::Delivered
            : StockItemStatus::InStock;

        $stockItem->update([
            'current_status' => $targetStatus,
            'is_available' => false,
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
            'to_status' => $targetStatus->value,
            'performed_by' => (int) $data['updated_by'],
            'remarks' => $data['remarks'] ?? 'Repair cancelled',
        ]);

    }

    private function handleReturnToCustomer(Repair $repair, array $data): void
    {
        $stockItem = StockItem::query()->lockForUpdate()->findOrFail((int) $repair->stock_item_id);
        $stockItem->update([
            'current_status' => StockItemStatus::Delivered,
            'is_available' => false,
            'last_movement_at' => now(),
        ]);

        $remarks = $data['remarks'] ?? 'Returned to customer';
        if (! empty($data['return_tracking_number'])) {
            $remarks .= ' | Tracking: '.$data['return_tracking_number'];
        }

        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $stockItem->product_id,
            'stock_item_id' => $stockItem->id,
            'movement_type' => MovementType::RepairReturnToCustomer,
            'reference_table' => 'repairs',
            'reference_id' => $repair->id,
            'qty_in' => 0,
            'qty_out' => 1,
            'from_status' => StockItemStatus::UnderRepair->value,
            'to_status' => StockItemStatus::Delivered->value,
            'performed_by' => (int) $data['updated_by'],
            'remarks' => $remarks,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateStatusPayload(RepairFlow $flow, RepairStatus $status, array $data): void
    {
        if ($flow === RepairFlow::Internal && in_array($status, [RepairStatus::ReadyToReturn, RepairStatus::ReturnedToCustomer], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'repair_status' => ['Internal repairs cannot use customer return statuses.'],
            ]);
        }

        if ($flow === RepairFlow::Customer && $status === RepairStatus::Completed) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'repair_status' => ['Customer-owned repairs must move to READY_TO_RETURN before RETURNED_TO_CUSTOMER.'],
            ]);
        }

        if ($status === RepairStatus::ReturnedToCustomer && empty($data['returned_to_customer_date'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'returned_to_customer_date' => ['Return to customer date is required when marking repair as RETURNED_TO_CUSTOMER.'],
            ]);
        }
    }
}
