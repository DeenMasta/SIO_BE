<?php

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Inventory\LowStockAlertService;
use App\Application\Support\AuditLogger;
use App\Domain\InventoryCore\Enums\MissingItemReportStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\MissingItemReport;
use App\Models\Stocktake;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteStocktakeUseCase implements UseCase
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly LowStockAlertService $lowStock,
    ) {}

    /**
     * @return int Number of unresolved missing-item reports removed with the stocktake.
     */
    public function execute(mixed $payload = null): int
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): int {
            $stocktake = Stocktake::query()
                ->lockForUpdate()
                ->findOrFail((int) $data['stocktake_id']);

            $reports = MissingItemReport::query()
                ->where('stocktake_id', $stocktake->id)
                ->lockForUpdate()
                ->get();

            if ($reports->contains('status', MissingItemReportStatus::Resolved)) {
                throw ValidationException::withMessages([
                    'stocktake' => ['A stocktake with resolved missing-item reports cannot be deleted.'],
                ]);
            }

            $unresolvedReports = $reports->filter(
                fn (MissingItemReport $report): bool => $report->status !== MissingItemReportStatus::Resolved,
            );
            $affectedProductIds = $unresolvedReports->pluck('product_id')->map(static fn (int|string $id): int => (int) $id)->unique()->values()->all();
            $beforeLowStockSnapshot = $this->lowStock->snapshotForProducts($affectedProductIds);

            foreach ($unresolvedReports as $report) {
                $this->auditLogger->log(
                    userId: (int) $data['deleted_by'],
                    moduleName: 'StockManagement',
                    entityName: 'MissingItemReport',
                    entityId: (int) $report->id,
                    action: AuditAction::Delete,
                    oldValues: [
                        'report_number' => $report->report_number,
                        'stocktake_id' => (int) $stocktake->id,
                        'status' => $report->status->value,
                        'missing_qty' => (int) $report->missing_qty,
                    ],
                );
            }

            MissingItemReport::query()
                ->whereKey($unresolvedReports->pluck('id'))
                ->delete();

            $this->auditLogger->log(
                userId: (int) $data['deleted_by'],
                moduleName: 'StockManagement',
                entityName: 'Stocktake',
                entityId: (int) $stocktake->id,
                action: AuditAction::Delete,
                oldValues: [
                    'stocktake_number' => $stocktake->stocktake_number,
                    'status' => $stocktake->status->value,
                    'submitted_at' => $stocktake->submitted_at?->toIso8601String(),
                    'deleted_unresolved_missing_item_reports' => $unresolvedReports->count(),
                ],
            );

            $stocktake->delete();

            $this->lowStock->notifyStatusTransitions($beforeLowStockSnapshot, $affectedProductIds, (int) $data['deleted_by']);

            return $unresolvedReports->count();
        });
    }
}
