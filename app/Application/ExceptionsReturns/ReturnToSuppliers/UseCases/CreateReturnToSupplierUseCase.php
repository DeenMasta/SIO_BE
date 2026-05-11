<?php

namespace App\Application\ExceptionsReturns\ReturnToSuppliers\UseCases;

use App\Application\Contracts\Repositories\ReturnToSupplierRepository;
use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Application\Support\StockBalanceUpdater;
use App\Application\Support\UserNotificationService;
use App\Domain\ExceptionsReturns\Enums\ExceptionTransactionStatus;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\ReturnToSupplier;
use App\Models\StockIn;
use App\Models\StockInLine;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateReturnToSupplierUseCase implements UseCase
{
    public function __construct(
        private readonly ReturnToSupplierRepository $returns,
        private readonly AuditLogger $auditLogger,
        private readonly StockBalanceUpdater $stockBalanceUpdater,
        private readonly UserNotificationService $userNotificationService,
    ) {
    }

    public function execute(mixed $payload = null): ReturnToSupplier
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): ReturnToSupplier {
            $stockIn = StockIn::query()
                ->with('lines.stockItems')
                ->findOrFail((int) $data['stock_in_id']);

            if ((int) $stockIn->supplier_id !== (int) $data['supplier_id']) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['Supplier must match the selected stock in record.'],
                ]);
            }

            $return = $this->returns->create([
                'rts_transaction_number' => $data['rts_transaction_number'],
                'supplier_id' => $data['supplier_id'],
                'stock_in_id' => $data['stock_in_id'],
                'return_date' => $data['return_date'],
                'status' => ExceptionTransactionStatus::Posted,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $data['created_by'],
            ]);

            $affectedProductIds = [];

            foreach ($data['lines'] as $index => $line) {
                $stockInLine = $this->resolveStockInLine($stockIn, $line, $index);
                $affectedProductIds[] = (int) $stockInLine->product_id;

                $lineModel = $return->lines()->create([
                    'product_id' => $line['product_id'],
                    'stock_item_id' => $line['stock_item_id'] ?? null,
                    'stock_in_line_id' => $stockInLine->id,
                    'qty' => (int) $line['qty'],
                    'reason_for_return' => $line['reason_for_return'],
                    'remarks' => $line['remarks'] ?? null,
                ]);

                if (!empty($line['stock_item_id'])) {
                    $this->postSerializedReturn($lineModel->id, $stockInLine, $line, (int) $data['created_by']);
                    continue;
                }

                $this->postNonSerializedReturn($lineModel->id, $stockInLine, $line, (int) $data['created_by']);
            }

            $this->stockBalanceUpdater->recomputeForProducts($affectedProductIds);

            $result = $return->fresh([
                'supplier',
                'stockIn',
                'lines.product',
                'lines.stockItem.product',
                'lines.stockInLine.product',
            ]);

            $this->auditLogger->log(
                userId: (int) $data['created_by'],
                moduleName: 'ExceptionsReturns',
                entityName: 'ReturnToSupplier',
                entityId: (int) $result->id,
                action: AuditAction::Post,
                newValues: [
                    'rts_transaction_number' => $result->rts_transaction_number,
                    'status' => $result->status?->value,
                ],
            );

            $this->userNotificationService->notifyAllActiveUsers(
                eventType: 'return-to-supplier.posted',
                title: 'Return to supplier posted',
                message: sprintf('Return to supplier %s was posted.', $result->rts_transaction_number),
                data: [
                    'return_to_supplier_id' => (int) $result->id,
                    'rts_transaction_number' => $result->rts_transaction_number,
                    'status' => $result->status?->value,
                    'supplier_id' => (int) $result->supplier_id,
                ],
                exceptUserId: (int) $data['created_by'],
                level: 'warning',
            );

            return $result;
        });
    }

    /**
     * @param array<string, mixed> $line
     */
    private function resolveStockInLine(StockIn $stockIn, array $line, int $index): StockInLine
    {
        $stockInLineId = (int) ($line['stock_in_line_id'] ?? 0);
        $stockInLine = $stockIn->lines->firstWhere('id', $stockInLineId);

        if (!$stockInLine instanceof StockInLine) {
            throw ValidationException::withMessages([
                "lines.$index.stock_in_line_id" => ['Selected stock in line does not belong to the selected stock in document.'],
            ]);
        }

        if ((int) $stockInLine->product_id !== (int) $line['product_id']) {
            throw ValidationException::withMessages([
                "lines.$index.product_id" => ['Product must match the selected stock in line.'],
            ]);
        }

        return $stockInLine;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function postSerializedReturn(int $returnLineId, StockInLine $stockInLine, array $line, int $performedBy): void
    {
        $stockItem = StockItem::query()
            ->lockForUpdate()
            ->with('stockInLine')
            ->findOrFail((int) $line['stock_item_id']);

        if ((int) $stockItem->stock_in_line_id !== (int) $stockInLine->id) {
            throw ValidationException::withMessages([
                'lines' => ['Scanned serial does not belong to the selected stock in line.'],
            ]);
        }

        if ((int) $line['qty'] !== 1) {
            throw ValidationException::withMessages([
                'lines' => ['Serialized returns must use qty 1 per stock item line.'],
            ]);
        }

        $fromStatus = $stockItem->current_status;

        if (!in_array($fromStatus, [StockItemStatus::Received, StockItemStatus::InStock], true) || !$stockItem->is_available) {
            throw ValidationException::withMessages([
                'lines' => ['Return to supplier only allows available stock-in serials that are currently RECEIVED or IN_STOCK.'],
            ]);
        }

        $stockItem->update([
            'current_status' => StockItemStatus::ReturnedToSupplier,
            'is_available' => false,
            'last_movement_at' => now(),
        ]);

        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $stockItem->product_id,
            'stock_item_id' => $stockItem->id,
            'movement_type' => MovementType::ReturnToSupplier,
            'reference_table' => 'return_to_supplier_lines',
            'reference_id' => $returnLineId,
            'qty_in' => 0,
            'qty_out' => 1,
            'from_status' => $fromStatus->value,
            'to_status' => StockItemStatus::ReturnedToSupplier->value,
            'performed_by' => $performedBy,
            'remarks' => $line['remarks'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $line
     */
    private function postNonSerializedReturn(int $returnLineId, StockInLine $stockInLine, array $line, int $performedBy): void
    {
        if ($stockInLine->stockItems()->exists()) {
            throw ValidationException::withMessages([
                'lines' => ['Serialized stock-in lines must be returned item-by-item using scanned stock items.'],
            ]);
        }

        $requestedQty = (int) $line['qty'];

        $alreadyReturnedQty = (int) $stockInLine->returnToSupplierLines()
            ->whereHas('returnToSupplier', fn ($query) => $query->where('status', ExceptionTransactionStatus::Posted->value))
            ->sum('qty');

        $returnableQty = max((int) $stockInLine->received_qty - $alreadyReturnedQty, 0);

        if ($requestedQty > $returnableQty) {
            throw ValidationException::withMessages([
                'lines' => [sprintf(
                    'Return qty for %s exceeds the remaining returnable qty on the selected stock in line (%d requested, %d available).',
                    $stockInLine->product?->product_name ?? "Product #{$stockInLine->product_id}",
                    $requestedQty,
                    $returnableQty,
                )],
            ]);
        }

        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => (int) $line['product_id'],
            'stock_item_id' => null,
            'movement_type' => MovementType::ReturnToSupplier,
            'reference_table' => 'return_to_supplier_lines',
            'reference_id' => $returnLineId,
            'qty_in' => 0,
            'qty_out' => $requestedQty,
            'from_status' => StockItemStatus::InStock->value,
            'to_status' => StockItemStatus::ReturnedToSupplier->value,
            'performed_by' => $performedBy,
            'remarks' => $line['remarks'] ?? null,
        ]);
    }
}
