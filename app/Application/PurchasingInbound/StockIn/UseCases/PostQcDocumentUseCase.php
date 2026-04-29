<?php

namespace App\Application\PurchasingInbound\StockIn\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\QcCheck;
use App\Models\QcDocument;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostQcDocumentUseCase implements UseCase
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Payload shape:
     * {
     *   document_number: string,
     *   date: string (ISO date),
     *   pic_id: int,
     *   remarks: ?string,
     *   lines: array<int, array{
     *       stock_item_id: int,
     *       result: 'PASSED'|'FAILED'|'PARTIAL',
     *       checked_conditions: array<string>,
     *       checked_accessories: array<string>,
     *       remarks: ?string
     *   }>
     * }
     */
    public function execute(mixed $payload = null): QcDocument
    {
        $data = (array) $payload;

        $documentNumber = (string) $data['document_number'];
        $date           = (string) $data['date'];
        $picId          = (int) $data['pic_id'];
        $stockInId      = (int) $data['stock_in_id'];
        $remarks        = ! empty($data['remarks']) ? (string) $data['remarks'] : null;
        $lines          = (array) ($data['lines'] ?? []);

        return DB::transaction(function () use ($documentNumber, $date, $picId, $stockInId, $remarks, $lines): QcDocument {
            $stockItemIds = array_column($lines, 'stock_item_id');

            $stockItemsCollection = StockItem::query()
                ->whereIn('id', $stockItemIds)
                ->lockForUpdate()
                ->get();

            $stockItems = [];
            foreach ($stockItemsCollection as $item) {
                $stockItems[$item->id] = $item;
            }

            if (count($stockItems) !== count($stockItemIds)) {
                $found   = array_keys($stockItems);
                $missing = array_values(array_diff($stockItemIds, $found));
                throw ValidationException::withMessages([
                    'lines' => [sprintf(
                        'The following stock item ID(s) were not found: %s.',
                        implode(', ', $missing)
                    )],
                ]);
            }

            foreach ($stockItems as $stockItem) {
                if ($stockItem->qc_status !== StockItemQcStatus::Pending) {
                    throw ValidationException::withMessages([
                        'lines' => [sprintf(
                            'Item %s has QC status %s. Only PENDING items can be QC checked.',
                            $stockItem->serial_number,
                            $stockItem->qc_status?->value ?? 'UNKNOWN'
                        )],
                    ]);
                }
            }

            // 1. Create QC Document
            $qcDocument = QcDocument::query()->create([
                'document_number' => $documentNumber,
                'date'            => $date,
                'pic_id'          => $picId,
                'stock_in_id'     => $stockInId,
                'status'          => 'POSTED',
                'remarks'         => $remarks,
            ]);

            // 2. Process Lines
            foreach ($lines as $line) {
                $stockItemId = (int) $line['stock_item_id'];
                /** @var StockItem $stockItem */
                $stockItem = $stockItems[$stockItemId];

                $result = StockItemQcStatus::from((string) $line['result']);
                $checkedConditions = (array) ($line['checked_conditions'] ?? []);
                $checkedAccessories = (array) ($line['checked_accessories'] ?? []);
                $lineRemarks = !empty($line['remarks']) ? (string) $line['remarks'] : null;

                $movementType = match($result) {
                    StockItemQcStatus::Passed => MovementType::QcPass,
                    default => MovementType::QcFail,
                };

                // Insert a new qc_items history row
                $qcCheck = QcCheck::query()->create([
                    'qc_document_id'      => $qcDocument->id,
                    'stock_item_id'       => $stockItem->id,
                    'result'              => $result->value,
                    'checked_at'          => now(),
                    'checked_conditions'  => $checkedConditions,
                    'checked_accessories' => $checkedAccessories,
                    'remarks'             => $lineRemarks,
                ]);

                // Update the cached qc_status on stock_items
                $stockItem->qc_status = $result;

                if ($result === StockItemQcStatus::Failed || $result === StockItemQcStatus::Partial) {
                    // Mark unavailable to prevent accidental dispatch
                    $stockItem->is_available = false;
                } elseif ($result === StockItemQcStatus::Passed) {
                    $stockItem->is_available = true;
                }

                $stockItem->save();

                // Write to stock_movements for full audit trail
                StockMovement::query()->create([
                    'movement_datetime' => now(),
                    'product_id'        => $stockItem->product_id,
                    'stock_item_id'     => $stockItem->id,
                    'movement_type'     => $movementType,
                    'reference_table'   => 'qc_items',
                    'reference_id'      => (int) $qcCheck->id,
                    'qty_in'            => 0,
                    'qty_out'           => 0,
                    'from_status'       => $stockItem->current_status->value,
                    'to_status'         => $stockItem->current_status->value,
                    'performed_by'      => $picId,
                    'remarks'           => $lineRemarks,
                ]);
            }

            $this->auditLogger->log(
                userId: $picId,
                moduleName: 'PurchasingInbound',
                entityName: 'QcDocument',
                entityId: (int) $qcDocument->id,
                action: AuditAction::Post,
                newValues: [
                    'document_number' => $documentNumber,
                    'total_lines'     => count($lines),
                ],
            );

            return $qcDocument->fresh('checks.stockItem', 'pic');
        });
    }
}
