<?php

namespace App\Application\ExceptionsReturns\Repairs\UseCases;

use App\Application\Contracts\Repositories\RepairRepository;
use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Domain\ExceptionsReturns\Enums\RepairFlow;
use App\Domain\ExceptionsReturns\Enums\RepairStatus;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\Repair;
use App\Models\StockItem;
use App\Models\StockOutLineItem;
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
            $repairFlow = RepairFlow::from((string) $data['repair_flow']);

            $customerId = $this->resolveCustomerId($stockItem, $repairFlow, $data);

            $fromStatus = $stockItem->current_status;

            $repair = $this->repairs->create([
                'repair_transaction_number' => $data['repair_transaction_number'],
                'repair_date' => $data['repair_date'],
                'stock_item_id' => $stockItem->id,
                'customer_id' => $customerId,
                'repair_flow' => $repairFlow,
                'issue_description' => $data['issue_description'],
                'repair_status' => RepairStatus::Open,
                'returned_to_customer_date' => null,
                'return_tracking_number' => null,
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
                newValues: [
                    'repair_transaction_number' => $repair->repair_transaction_number,
                    'repair_flow' => $repair->repair_flow?->value,
                    'repair_status' => $repair->repair_status?->value,
                    'customer_id' => $repair->customer_id,
                ],
            );

            return $repair;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCustomerId(StockItem $stockItem, RepairFlow $repairFlow, array $data): ?int
    {
        if ($repairFlow === RepairFlow::Customer) {
            if ($stockItem->current_status !== StockItemStatus::Delivered) {
                throw ValidationException::withMessages([
                    'stock_item_id' => ['Customer-owned repairs only allow stock items in DELIVERED status.'],
                ]);
            }

            $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : null;
            if ($customerId === null) {
                throw ValidationException::withMessages([
                    'customer_id' => ['Customer is required for customer-owned repairs.'],
                ]);
            }

            $deliveredCustomerId = StockOutLineItem::query()
                ->join('stock_out_lines', 'stock_out_lines.id', '=', 'stock_out_line_items.stock_out_line_id')
                ->join('stock_out', 'stock_out.id', '=', 'stock_out_lines.stock_out_id')
                ->where('stock_out_line_items.stock_item_id', $stockItem->id)
                ->orderByDesc('stock_out.id')
                ->value('stock_out.customer_id');

            if ($deliveredCustomerId === null) {
                throw ValidationException::withMessages([
                    'stock_item_id' => ['Delivered stock item has no linked stock out customer.'],
                ]);
            }

            if ((int) $deliveredCustomerId !== $customerId) {
                throw ValidationException::withMessages([
                    'customer_id' => ['Customer must match the original delivery customer for this serial number.'],
                ]);
            }

            return $customerId;
        }

        if (! in_array($stockItem->current_status, [StockItemStatus::InStock, StockItemStatus::Returned], true)) {
            throw ValidationException::withMessages([
                'stock_item_id' => ['Internal repairs only allow stock items in IN_STOCK or RETURNED status.'],
            ]);
        }

        if (! empty($data['customer_id'])) {
            throw ValidationException::withMessages([
                'customer_id' => ['Customer must be empty for internal repairs.'],
            ]);
        }

        return null;
    }
}
