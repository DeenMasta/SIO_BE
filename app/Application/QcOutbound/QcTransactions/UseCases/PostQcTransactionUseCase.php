<?php

namespace App\Application\QcOutbound\QcTransactions\UseCases;

use App\Application\Contracts\Repositories\QcTransactionRepository;
use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Application\Support\StockBalanceService;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\MasterData\Enums\ProductType;
use App\Domain\QcOutbound\Enums\QcResult;
use App\Domain\QcOutbound\Enums\QcTransactionStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\Product;
use App\Models\QcTransaction;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostQcTransactionUseCase implements UseCase
{
    public function __construct(
        private readonly QcTransactionRepository $qcTransactions,
        private readonly AuditLogger $auditLogger,
        private readonly StockBalanceService $stockBalances,
    )
    {
    }

    public function execute(mixed $payload = null): QcTransaction
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): QcTransaction {
            $qcTransaction = $this->qcTransactions->create([
                'qc_reference_number' => $data['qc_reference_number'],
                'stock_in_id' => $data['stock_in_id'],
                'qc_date' => $data['qc_date'],
                'qc_by' => $data['qc_by'],
                'status' => QcTransactionStatus::Posted,
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $product = Product::query()->findOrFail((int) $line['product_id']);
                $result = QcResult::from((string) $line['qc_result']);

                if (in_array($product->product_type, [ProductType::Device, ProductType::Accessory], true)) {
                    $stockItemIds = array_values(array_map('intval', Arr::wrap($line['stock_item_ids'] ?? [])));

                    if ($stockItemIds === []) {
                        throw ValidationException::withMessages([
                            'lines' => ['Serialized products require stock_item_ids in QC payload.'],
                        ]);
                    }

                    $stockItems = StockItem::query()
                        ->whereIn('id', $stockItemIds)
                        ->where('product_id', $product->id)
                        ->where('stock_in_line_id', (int) $line['stock_in_line_id'])
                        ->get();

                    if ($stockItems->count() !== count($stockItemIds)) {
                        throw ValidationException::withMessages([
                            'lines' => ['Some stock items are invalid for this QC line.'],
                        ]);
                    }

                    foreach ($stockItems as $stockItem) {
                        if ($stockItem->current_status !== StockItemStatus::Received) {
                            throw ValidationException::withMessages([
                                'lines' => ['QC is only allowed for stock items in RECEIVED status.'],
                            ]);
                        }

                        $toStatus = $result === QcResult::Pass ? StockItemStatus::InStock : StockItemStatus::Received;
                        $stockItem->update([
                            'current_status' => $toStatus,
                            'is_available' => $result === QcResult::Pass,
                            'last_movement_at' => now(),
                        ]);

                        $qcLine = $qcTransaction->lines()->create([
                            'stock_in_line_id' => (int) $line['stock_in_line_id'],
                            'product_id' => $product->id,
                            'stock_item_id' => $stockItem->id,
                            'qc_result' => $result,
                            'qty_pass' => $result === QcResult::Pass ? 1 : 0,
                            'qty_fail' => $result === QcResult::Fail ? 1 : 0,
                            'remarks' => $line['remarks'] ?? null,
                        ]);

                        StockMovement::query()->create([
                            'movement_datetime' => now(),
                            'product_id' => $product->id,
                            'stock_item_id' => $stockItem->id,
                            'movement_type' => $result === QcResult::Pass ? MovementType::QcPass : MovementType::QcFail,
                            'reference_table' => 'qc_transaction_lines',
                            'reference_id' => (int) $qcLine->id,
                            'qty_in' => $result === QcResult::Pass ? 1 : 0,
                            'qty_out' => $result === QcResult::Fail ? 1 : 0,
                            'from_status' => StockItemStatus::Received->value,
                            'to_status' => $toStatus->value,
                            'performed_by' => (int) $data['qc_by'],
                            'remarks' => $line['remarks'] ?? null,
                        ]);

                        if ($result === QcResult::Pass) {
                            $this->stockBalances->transferStatus($product->id, StockItemStatus::Received, StockItemStatus::InStock, 1);
                        }
                    }

                    continue;
                }

                $qtyPass = (int) ($line['qty_pass'] ?? 0);
                $qtyFail = (int) ($line['qty_fail'] ?? 0);

                if ($result === QcResult::Pass && $qtyPass < 1) {
                    throw ValidationException::withMessages([
                        'lines' => ['PASS result requires qty_pass for non-serialized products.'],
                    ]);
                }

                if ($result === QcResult::Fail && $qtyFail < 1) {
                    throw ValidationException::withMessages([
                        'lines' => ['FAIL result requires qty_fail for non-serialized products.'],
                    ]);
                }

                $qcLine = $qcTransaction->lines()->create([
                    'stock_in_line_id' => (int) $line['stock_in_line_id'],
                    'product_id' => $product->id,
                    'stock_item_id' => null,
                    'qc_result' => $result,
                    'qty_pass' => $qtyPass,
                    'qty_fail' => $qtyFail,
                    'remarks' => $line['remarks'] ?? null,
                ]);

                StockMovement::query()->create([
                    'movement_datetime' => now(),
                    'product_id' => $product->id,
                    'stock_item_id' => null,
                    'movement_type' => $result === QcResult::Pass ? MovementType::QcPass : MovementType::QcFail,
                    'reference_table' => 'qc_transaction_lines',
                    'reference_id' => (int) $qcLine->id,
                    'qty_in' => $qtyPass,
                    'qty_out' => $qtyFail,
                    'performed_by' => (int) $data['qc_by'],
                    'remarks' => $line['remarks'] ?? null,
                ]);

                if ($qtyPass > 0) {
                    $this->stockBalances->transferStatus($product->id, StockItemStatus::Received, StockItemStatus::InStock, $qtyPass);
                }

                if ($qtyFail > 0) {
                    $this->stockBalances->decrementStatus($product->id, StockItemStatus::Received, $qtyFail);
                }
            }

            $result = $qcTransaction->fresh('lines');

            $this->auditLogger->log(
                userId: (int) $data['qc_by'],
                moduleName: 'QcOutbound',
                entityName: 'QcTransaction',
                entityId: (int) $result->id,
                action: AuditAction::Post,
                newValues: ['qc_reference_number' => $result->qc_reference_number, 'status' => $result->status?->value],
            );

            return $result;
        });
    }
}
