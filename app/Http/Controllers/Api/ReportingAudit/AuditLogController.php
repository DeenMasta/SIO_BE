<?php

namespace App\Http\Controllers\Api\ReportingAudit;

use App\Application\ReportingAudit\AuditLogs\UseCases\ListAuditLogsUseCase;
use App\Application\ReportingAudit\Reports\Services\ExportService;
use App\Application\Support\ApiResponse;
use App\Application\Support\AuditLogger;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReportingAudit\AuditLog\AuditLogReportRequest;
use App\Http\Resources\Api\ReportingAudit\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function __construct(
        private readonly ListAuditLogsUseCase $auditLogs,
        private readonly AuditLogger $auditLogger,
        private readonly ExportService $exportService,
    ) {
    }

    public function index(AuditLogReportRequest $request): JsonResponse
    {
        $records = $this->auditLogs->execute($request->validated());

        return ApiResponse::success(
            AuditLogResource::collection($records->items()),
            'Audit logs retrieved successfully.',
            meta: [
                'pagination' => [
                    'current_page' => $records->currentPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                    'last_page' => $records->lastPage(),
                ],
            ],
        );
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        $auditLog->load('user:id,name');

        return ApiResponse::success(
            new AuditLogResource($auditLog),
            'Audit log retrieved successfully.',
        );
    }

    public function export(AuditLogReportRequest $request): StreamedResponse
    {
        $validated = $request->validated();
        $format = strtolower($validated['format'] ?? 'csv');
        $filters = collect($validated)->except('format')->toArray();

        $rows = $this->auditLogs->exportRows($filters);
        $filename = 'audit-logs-'.now()->format('Ymd_His');

        // Log export action
        $this->auditLogger->log(
            userId: (int) $request->user()->id,
            moduleName: 'ReportingAudit',
            entityName: 'AuditLogReport',
            entityId: 0,
            action: AuditAction::Export,
            newValues: ['filters' => $filters, 'filename' => $filename, 'format' => $format],
        );

        // Redact sensitive fields for export
        $redactedRows = $rows->map(function ($row) {
            return (object) [
                'id' => $row->id,
                'created_at' => $row->created_at,
                'user_id' => $row->user_id,
                'module_name' => $row->module_name,
                'entity_name' => $row->entity_name,
                'entity_id' => $row->entity_id,
                'action' => $row->action,
                'old_values' => AuditLogResource::redact($row->old_values),
                'new_values' => AuditLogResource::redact($row->new_values),
            ];
        });

        $headers = [
            'id',
            'created_at',
            'user_id',
            'module_name',
            'entity_name',
            'entity_id',
            'action',
            'old_values',
            'new_values',
        ];

        return $this->exportService->export(
            rows: $redactedRows,
            headers: $headers,
            filename: $filename,
            format: $format,
        );
    }
}
