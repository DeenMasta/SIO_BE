<?php

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Inventory\LowStockAlertService;
use App\Application\Support\AuditLogger;
use App\Application\Support\StockBalanceUpdater;
use App\Domain\InventoryCore\Enums\MissingItemReportStatus;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\QcOutbound\Enums\StockOutStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\MissingItemReport;
use App\Models\StockMovement;
use App\Models\StockOut;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResolveMissingItemReportUseCase implements UseCase
{
    public function __construct(private readonly AuditLogger $auditLogger, private readonly StockBalanceUpdater $balances, private readonly LowStockAlertService $lowStock) {}

    public function execute(mixed $payload = null): MissingItemReport
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): MissingItemReport {
            $report = MissingItemReport::query()->with('stockItem')->lockForUpdate()->findOrFail($data['report_id']);
            if ($report->status === MissingItemReportStatus::Resolved) {
                throw ValidationException::withMessages(['report' => ['This missing-item report has already been resolved.']]);
            }

            $resolution = $data['resolution_type'];
            $stockOutId = null;
            if ($resolution === 'STOCK_OUT') {
                $stockOutId = (int) ($data['stock_out_id'] ?? 0);
                $this->assertStockOutExplainsReport($report, $stockOutId);
            }

            if ($resolution === 'WRITE_OFF') {
                $this->writeOff($report, (int) $data['resolved_by'], (string) ($data['resolution_notes'] ?? ''));
            }

            $report->update([
                'status' => MissingItemReportStatus::Resolved,
                'resolution_type' => $resolution,
                'stock_out_id' => $stockOutId,
                'resolution_notes' => $data['resolution_notes'] ?? null,
                'resolved_by' => $data['resolved_by'],
                'resolved_at' => now(),
            ]);

            $this->auditLogger->log((int) $data['resolved_by'], 'StockManagement', 'MissingItemReport', (int) $report->id, AuditAction::Update, newValues: [
                'resolution_type' => $resolution,
                'stock_out_id' => $stockOutId,
            ]);

            return $report->fresh(['product', 'stockItem', 'stockOut', 'stocktake', 'reportedByUser', 'resolvedByUser']);
        });
    }

    private function assertStockOutExplainsReport(MissingItemReport $report, int $stockOutId): void
    {
        $stockOut = StockOut::query()->with('lines.lineItems')->lockForUpdate()->find($stockOutId);
        if (! $stockOut || $stockOut->status !== StockOutStatus::Posted) {
            throw ValidationException::withMessages(['stock_out_id' => ['Select a posted stock-out record that documents this missing item.']]);
        }

        if ($report->stock_item_id !== null) {
            $exists = $stockOut->lines->flatMap->lineItems->contains('stock_item_id', $report->stock_item_id);
            if (! $exists) {
                throw ValidationException::withMessages(['stock_out_id' => ['The selected stock out does not contain this serial-numbered item.']]);
            }

            return;
        }

        $stockOutQty = (int) $stockOut->lines->where('product_id', $report->product_id)->sum('qty');
        $alreadyLinkedQty = (int) MissingItemReport::query()
            ->where('stock_out_id', $stockOut->id)
            ->where('product_id', $report->product_id)
            ->where('status', MissingItemReportStatus::Resolved->value)
            ->sum('missing_qty');
        if ($stockOutQty < $alreadyLinkedQty + $report->missing_qty) {
            throw ValidationException::withMessages(['stock_out_id' => ['The selected stock out does not have enough of this product to explain this shortage.']]);
        }
    }

    private function writeOff(MissingItemReport $report, int $userId, string $remarks): void
    {
        $before = $this->lowStock->snapshotForProducts([$report->product_id]);
        if ($report->stockItem !== null) {
            $item = $report->stockItem;
            if ($item->current_status !== StockItemStatus::InStock || ! $item->is_available || $item->qc_status !== StockItemQcStatus::Passed) {
                throw ValidationException::withMessages(['report' => ['This serial is no longer available to write off. Resolve it through its recorded movement instead.']]);
            }
            $item->update(['current_status' => StockItemStatus::Missing, 'is_available' => false, 'last_movement_at' => now()]);
            StockMovement::query()->create([
                'movement_datetime' => now(), 'product_id' => $report->product_id, 'stock_item_id' => $item->id,
                'movement_type' => MovementType::StocktakeWriteOff, 'reference_table' => 'missing_item_reports', 'reference_id' => $report->id,
                'qty_in' => 0, 'qty_out' => 1, 'from_status' => StockItemStatus::InStock->value, 'to_status' => StockItemStatus::Missing->value,
                'performed_by' => $userId, 'remarks' => $remarks ?: 'Stocktake shortage write-off.',
            ]);
        } else {
            $totals = StockMovement::query()->where('product_id', $report->product_id)->whereNull('stock_item_id')
                ->selectRaw("COALESCE(SUM(CASE WHEN to_status = 'IN_STOCK' THEN qty_in ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN from_status = 'IN_STOCK' THEN qty_out ELSE 0 END), 0) as available_qty")
                ->lockForUpdate()->first();
            if ((int) ($totals->available_qty ?? 0) < $report->missing_qty) {
                throw ValidationException::withMessages(['report' => ['The current available stock is lower than this shortage. Investigate later movements before writing it off.']]);
            }
            StockMovement::query()->create([
                'movement_datetime' => now(), 'product_id' => $report->product_id, 'stock_item_id' => null,
                'movement_type' => MovementType::StocktakeWriteOff, 'reference_table' => 'missing_item_reports', 'reference_id' => $report->id,
                'qty_in' => 0, 'qty_out' => $report->missing_qty, 'from_status' => StockItemStatus::InStock->value, 'to_status' => StockItemStatus::Missing->value,
                'performed_by' => $userId, 'remarks' => $remarks ?: 'Stocktake shortage write-off.',
            ]);
        }
        $this->balances->recomputeForProducts([$report->product_id]);
        $this->lowStock->notifyStatusTransitions($before, [$report->product_id], $userId);
    }
}
