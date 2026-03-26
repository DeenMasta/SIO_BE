<?php

namespace App\Http\Controllers\Api\ReportingAudit;

use App\Application\ReportingAudit\AuditLogs\UseCases\ListAuditLogsUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReportingAudit\AuditLog\AuditLogReportRequest;
use App\Http\Resources\Api\ReportingAudit\AuditLogResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function __construct(private readonly ListAuditLogsUseCase $auditLogs)
    {
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

    public function export(AuditLogReportRequest $request): StreamedResponse
    {
        $rows = $this->auditLogs->exportRows($request->validated());
        $filename = 'audit-logs-'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['id', 'created_at', 'user_id', 'module_name', 'entity_name', 'entity_id', 'action', 'old_values', 'new_values']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    (int) $row->id,
                    $row->created_at?->toISOString(),
                    $row->user_id,
                    $row->module_name,
                    $row->entity_name,
                    $row->entity_id,
                    is_object($row->action) ? $row->action->value : $row->action,
                    json_encode(AuditLogResource::redact($row->old_values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode(AuditLogResource::redact($row->new_values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
