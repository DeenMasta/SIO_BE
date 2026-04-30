<?php

namespace App\Application\ExceptionsReturns\ReturnToSuppliers\UseCases;

use App\Application\Contracts\Repositories\ReturnToSupplierRepository;
use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Application\Support\StockBalanceUpdater;
use App\Domain\ExceptionsReturns\Enums\ExceptionTransactionStatus;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\ReturnToSupplier;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelReturnToSupplierUseCase implements UseCase
{
    public function __construct(
        private readonly ReturnToSupplierRepository $returns,
        private readonly AuditLogger $auditLogger,
        private readonly StockBalanceUpdater $stockBalanceUpdater,
    ) {
    }

    public function execute(mixed $payload = null): ReturnToSupplier
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): ReturnToSupplier {
            $return = $this->returns->findOrFail((int) $data['return_to_supplier_id']);

            if ($return->status !== ExceptionTransactionStatus::Posted) {
                throw ValidationException::withMessages([
                    'status' => ['Only POSTED return-to-supplier transactions can be cancelled.'],
                ]);
            }

            $affectedProductIds = [];

            foreach ($return->lines as $line) {
                $affectedProductIds[] = (int) $line->product_id;

                $originalMovement = StockMovement::query()
                    ->where('reference_table', 'return_to_supplier_lines')
                    ->where('reference_id', (int) $line->id)
                    ->where('movement_type', MovementType::ReturnToSupplier->value)
                    ->latest('id')
                    ->first();

                $restoreStatus = $originalMovement?->from_status ?: StockItemStatus::InStock->value;

                if ($line->stock_item_id !== null) {
                    $stockItem = StockItem::query()->lockForUpdate()->findOrFail((int) $line->stock_item_id);

                    $stockItem->update([
                        'current_status' => $restoreStatus,
                        'is_available' => in_array($restoreStatus, [
                            StockItemStatus::Received->value,
                            StockItemStatus::InStock->value,
                        ], true),
                        'last_movement_at' => now(),
                    ]);

                    StockMovement::query()->create([
                        'movement_datetime' => now(),
                        'product_id' => (int) $line->product_id,
                        'stock_item_id' => (int) $line->stock_item_id,
                        'movement_type' => MovementType::ReturnToSupplierCancelled,
                        'reference_table' => 'return_to_supplier',
                        'reference_id' => (int) $return->id,
                        'qty_in' => 1,
                        'qty_out' => 0,
                        'from_status' => StockItemStatus::ReturnedToSupplier->value,
                        'to_status' => $restoreStatus,
                        'performed_by' => (int) $data['cancelled_by'],
                        'remarks' => $data['remarks'] ?? 'Return to supplier cancelled',
                    ]);

                    continue;
                }

                StockMovement::query()->create([
                    'movement_datetime' => now(),
                    'product_id' => (int) $line->product_id,
                    'stock_item_id' => null,
                    'movement_type' => MovementType::ReturnToSupplierCancelled,
                    'reference_table' => 'return_to_supplier',
                    'reference_id' => (int) $return->id,
                    'qty_in' => (int) $line->qty,
                    'qty_out' => 0,
                    'from_status' => StockItemStatus::ReturnedToSupplier->value,
                    'to_status' => $restoreStatus,
                    'performed_by' => (int) $data['cancelled_by'],
                    'remarks' => $data['remarks'] ?? 'Return to supplier cancelled',
                ]);
            }

            $return->status = ExceptionTransactionStatus::Cancelled;
            $return->remarks = $data['remarks'] ?? $return->remarks;
            $return->save();

            $this->stockBalanceUpdater->recomputeForProducts($affectedProductIds);

            $this->auditLogger->log(
                userId: (int) $data['cancelled_by'],
                moduleName: 'ExceptionsReturns',
                entityName: 'ReturnToSupplier',
                entityId: (int) $return->id,
                action: AuditAction::Cancel,
                oldValues: ['status' => ExceptionTransactionStatus::Posted->value],
                newValues: ['status' => ExceptionTransactionStatus::Cancelled->value],
            );

            return $return->fresh([
                'supplier',
                'stockIn',
                'lines.product',
                'lines.stockItem.product',
                'lines.stockInLine.product',
            ]);
        });
    }
}
