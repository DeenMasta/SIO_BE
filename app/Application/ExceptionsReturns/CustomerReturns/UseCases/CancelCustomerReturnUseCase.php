<?php

namespace App\Application\ExceptionsReturns\CustomerReturns\UseCases;

use App\Application\Contracts\Repositories\CustomerReturnRepository;
use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Application\Support\StockBalanceService;
use App\Domain\ExceptionsReturns\Enums\CustomerReturnNextAction;
use App\Domain\ExceptionsReturns\Enums\ExceptionTransactionStatus;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\CustomerReturn;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelCustomerReturnUseCase implements UseCase
{
    public function __construct(
        private readonly CustomerReturnRepository $returns,
        private readonly AuditLogger $auditLogger,
        private readonly StockBalanceService $stockBalances,
    ) {
    }

    public function execute(mixed $payload = null): CustomerReturn
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): CustomerReturn {
            $return = $this->returns->findOrFail((int) $data['customer_return_id']);

            if ($return->status !== ExceptionTransactionStatus::Posted) {
                throw ValidationException::withMessages([
                    'status' => ['Only POSTED customer return transactions can be cancelled.'],
                ]);
            }

            foreach ($return->lines as $line) {
                $fromStatus = $this->targetStatusForNextAction((string) $line->next_action);

                if ($line->stock_item_id !== null) {
                    $stockItem = StockItem::query()->lockForUpdate()->findOrFail((int) $line->stock_item_id);

                    $stockItem->update([
                        'current_status' => StockItemStatus::Delivered,
                        'is_available' => false,
                        'last_movement_at' => now(),
                    ]);

                    StockMovement::query()->create([
                        'movement_datetime' => now(),
                        'product_id' => (int) $line->product_id,
                        'stock_item_id' => (int) $line->stock_item_id,
                        'movement_type' => MovementType::CustomerReturnCancelled,
                        'reference_table' => 'customer_returns',
                        'reference_id' => (int) $return->id,
                        'qty_in' => 0,
                        'qty_out' => 1,
                        'from_status' => $fromStatus->value,
                        'to_status' => StockItemStatus::Delivered->value,
                        'performed_by' => (int) $data['cancelled_by'],
                        'remarks' => $data['remarks'] ?? 'Customer return cancelled',
                    ]);

                    $this->stockBalances->transferStatus((int) $line->product_id, $fromStatus, StockItemStatus::Delivered, 1);

                    continue;
                }

                StockMovement::query()->create([
                    'movement_datetime' => now(),
                    'product_id' => (int) $line->product_id,
                    'stock_item_id' => null,
                    'movement_type' => MovementType::CustomerReturnCancelled,
                    'reference_table' => 'customer_returns',
                    'reference_id' => (int) $return->id,
                    'qty_in' => 0,
                    'qty_out' => (int) $line->qty,
                    'from_status' => $fromStatus->value,
                    'to_status' => StockItemStatus::Delivered->value,
                    'performed_by' => (int) $data['cancelled_by'],
                    'remarks' => $data['remarks'] ?? 'Customer return cancelled',
                ]);

                $this->stockBalances->transferStatus((int) $line->product_id, $fromStatus, StockItemStatus::Delivered, (int) $line->qty);
            }

            $return->status = ExceptionTransactionStatus::Cancelled;
            $return->remarks = $data['remarks'] ?? $return->remarks;
            $return->save();

            $this->auditLogger->log(
                userId: (int) $data['cancelled_by'],
                moduleName: 'ExceptionsReturns',
                entityName: 'CustomerReturn',
                entityId: (int) $return->id,
                action: AuditAction::Cancel,
                oldValues: ['status' => ExceptionTransactionStatus::Posted->value],
                newValues: ['status' => ExceptionTransactionStatus::Cancelled->value],
            );

            return $return->fresh('lines');
        });
    }

    private function targetStatusForNextAction(string $nextAction): StockItemStatus
    {
        return match (CustomerReturnNextAction::from($nextAction)) {
            CustomerReturnNextAction::Restock => StockItemStatus::InStock,
            CustomerReturnNextAction::Repair => StockItemStatus::UnderRepair,
            CustomerReturnNextAction::Replace => StockItemStatus::Returned,
            CustomerReturnNextAction::Scrap => StockItemStatus::ReturnedToSupplier,
        };
    }
}
