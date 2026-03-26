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
use App\Models\StockOut;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCustomerReturnUseCase implements UseCase
{
    public function __construct(
        private readonly CustomerReturnRepository $returns,
        private readonly AuditLogger $auditLogger,
        private readonly StockBalanceService $stockBalances,
    )
    {
    }

    public function execute(mixed $payload = null): CustomerReturn
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): CustomerReturn {
            $stockOut = StockOut::query()->findOrFail((int) $data['original_stock_out_id']);
            if ((int) $stockOut->customer_id !== (int) $data['customer_id']) {
                throw ValidationException::withMessages([
                    'customer_id' => ['Customer must match original stock out customer.'],
                ]);
            }

            $return = $this->returns->create([
                'return_transaction_number' => $data['return_transaction_number'],
                'return_date' => $data['return_date'],
                'customer_id' => $data['customer_id'],
                'original_invoice_number' => $data['original_invoice_number'],
                'original_stock_out_id' => $data['original_stock_out_id'],
                'status' => ExceptionTransactionStatus::Posted,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $data['created_by'],
            ]);

            foreach ($data['lines'] as $line) {
                $stockItemId = $line['stock_item_id'] ?? null;
                $nextAction = CustomerReturnNextAction::from((string) $line['next_action']);
                $targetStatus = $this->targetStatusForNextAction($nextAction);
                $targetAvailability = $nextAction === CustomerReturnNextAction::Restock;

                $lineModel = $return->lines()->create([
                    'original_stock_out_line_id' => $line['original_stock_out_line_id'] ?? null,
                    'product_id' => $line['product_id'],
                    'stock_item_id' => $stockItemId,
                    'qty' => (int) $line['qty'],
                    'reason_for_return' => $line['reason_for_return'],
                    'condition_on_return' => $line['condition_on_return'] ?? null,
                    'next_action' => $line['next_action'] ?? null,
                    'remarks' => $line['remarks'] ?? null,
                ]);

                if ($stockItemId !== null) {
                    $stockItem = StockItem::query()->lockForUpdate()->findOrFail((int) $stockItemId);
                    if ($stockItem->current_status !== StockItemStatus::Delivered) {
                        throw ValidationException::withMessages([
                            'lines' => ['Customer return only allows stock items in DELIVERED status.'],
                        ]);
                    }

                    $stockItem->update([
                        'current_status' => $targetStatus,
                        'is_available' => $targetAvailability,
                        'last_movement_at' => now(),
                    ]);

                    StockMovement::query()->create([
                        'movement_datetime' => now(),
                        'product_id' => $stockItem->product_id,
                        'stock_item_id' => $stockItem->id,
                        'movement_type' => MovementType::CustomerReturn,
                        'reference_table' => 'customer_return_lines',
                        'reference_id' => $lineModel->id,
                        'qty_in' => 1,
                        'qty_out' => 0,
                        'from_status' => StockItemStatus::Delivered->value,
                        'to_status' => $targetStatus->value,
                        'performed_by' => (int) $data['created_by'],
                        'remarks' => $line['remarks'] ?? null,
                    ]);

                    $this->stockBalances->transferStatus($stockItem->product_id, StockItemStatus::Delivered, $targetStatus, 1);

                    continue;
                }

                StockMovement::query()->create([
                    'movement_datetime' => now(),
                    'product_id' => (int) $line['product_id'],
                    'stock_item_id' => null,
                    'movement_type' => MovementType::CustomerReturn,
                    'reference_table' => 'customer_return_lines',
                    'reference_id' => $lineModel->id,
                    'qty_in' => (int) $line['qty'],
                    'qty_out' => 0,
                    'from_status' => StockItemStatus::Delivered->value,
                    'to_status' => $targetStatus->value,
                    'performed_by' => (int) $data['created_by'],
                    'remarks' => $line['remarks'] ?? null,
                ]);

                $this->stockBalances->transferStatus((int) $line['product_id'], StockItemStatus::Delivered, $targetStatus, (int) $line['qty']);
            }

            $result = $return->fresh('lines');

            $this->auditLogger->log(
                userId: (int) $data['created_by'],
                moduleName: 'ExceptionsReturns',
                entityName: 'CustomerReturn',
                entityId: (int) $result->id,
                action: AuditAction::Post,
                newValues: ['return_transaction_number' => $result->return_transaction_number, 'status' => $result->status?->value],
            );

            return $result;
        });
    }

    private function targetStatusForNextAction(CustomerReturnNextAction $nextAction): StockItemStatus
    {
        return match ($nextAction) {
            CustomerReturnNextAction::Restock => StockItemStatus::InStock,
            CustomerReturnNextAction::Repair => StockItemStatus::UnderRepair,
            CustomerReturnNextAction::Replace => StockItemStatus::Returned,
            CustomerReturnNextAction::Scrap => StockItemStatus::ReturnedToSupplier,
        };
    }
}
