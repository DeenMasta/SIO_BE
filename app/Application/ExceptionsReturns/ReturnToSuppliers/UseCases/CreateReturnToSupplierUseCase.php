<?php

namespace App\Application\ExceptionsReturns\ReturnToSuppliers\UseCases;

use App\Application\Contracts\Repositories\ReturnToSupplierRepository;
use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Domain\ExceptionsReturns\Enums\ExceptionTransactionStatus;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\ReturnToSupplier;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateReturnToSupplierUseCase implements UseCase
{
    public function __construct(
        private readonly ReturnToSupplierRepository $returns,
        private readonly AuditLogger $auditLogger,
    )
    {
    }

    public function execute(mixed $payload = null): ReturnToSupplier
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): ReturnToSupplier {
            $return = $this->returns->create([
                'rts_transaction_number' => $data['rts_transaction_number'],
                'supplier_id' => $data['supplier_id'],
                'stock_in_id' => $data['stock_in_id'] ?? null,
                'return_date' => $data['return_date'],
                'status' => ExceptionTransactionStatus::Posted,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $data['created_by'],
            ]);

            foreach ($data['lines'] as $line) {
                $stockItemId = $line['stock_item_id'] ?? null;

                $lineModel = $return->lines()->create([
                    'product_id' => $line['product_id'],
                    'stock_item_id' => $stockItemId,
                    'qty' => (int) $line['qty'],
                    'reason_for_return' => $line['reason_for_return'],
                    'remarks' => $line['remarks'] ?? null,
                ]);

                if ($stockItemId !== null) {
                    $stockItem = StockItem::query()->lockForUpdate()->findOrFail((int) $stockItemId);

                    if ($stockItem->current_status !== StockItemStatus::Received) {
                        throw ValidationException::withMessages([
                            'lines' => ['Return to supplier only allows stock items in RECEIVED status.'],
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
                        'reference_id' => $lineModel->id,
                        'qty_in' => 0,
                        'qty_out' => 1,
                        'from_status' => StockItemStatus::Received->value,
                        'to_status' => StockItemStatus::ReturnedToSupplier->value,
                        'performed_by' => (int) $data['created_by'],
                        'remarks' => $line['remarks'] ?? null,
                    ]);

                    continue;
                }

                StockMovement::query()->create([
                    'movement_datetime' => now(),
                    'product_id' => (int) $line['product_id'],
                    'stock_item_id' => null,
                    'movement_type' => MovementType::ReturnToSupplier,
                    'reference_table' => 'return_to_supplier_lines',
                    'reference_id' => $lineModel->id,
                    'qty_in' => 0,
                    'qty_out' => (int) $line['qty'],
                    'performed_by' => (int) $data['created_by'],
                    'remarks' => $line['remarks'] ?? null,
                ]);

            }

            $result = $return->fresh('lines');

            $this->auditLogger->log(
                userId: (int) $data['created_by'],
                moduleName: 'ExceptionsReturns',
                entityName: 'ReturnToSupplier',
                entityId: (int) $result->id,
                action: AuditAction::Post,
                newValues: ['rts_transaction_number' => $result->rts_transaction_number, 'status' => $result->status?->value],
            );

            return $result;
        });
    }
}
