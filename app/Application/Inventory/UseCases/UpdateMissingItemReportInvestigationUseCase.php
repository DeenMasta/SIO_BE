<?php

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Domain\InventoryCore\Enums\MissingItemReportStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\MissingItemReport;
use Illuminate\Validation\ValidationException;

final class UpdateMissingItemReportInvestigationUseCase implements UseCase
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(mixed $payload = null): MissingItemReport
    {
        $data = (array) $payload;
        $report = MissingItemReport::query()->findOrFail($data['report_id']);
        if ($report->status === MissingItemReportStatus::Resolved) {
            throw ValidationException::withMessages(['report' => ['A resolved report cannot be changed.']]);
        }
        $report->update(['status' => MissingItemReportStatus::Investigating, 'investigation_notes' => $data['investigation_notes']]);
        $this->auditLogger->log((int) $data['updated_by'], 'StockManagement', 'MissingItemReport', (int) $report->id, AuditAction::Update, newValues: ['status' => 'INVESTIGATING']);

        return $report->fresh(['product', 'stockItem', 'stockOut', 'stocktake', 'reportedByUser', 'resolvedByUser']);
    }
}
