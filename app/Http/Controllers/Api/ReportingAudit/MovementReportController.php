<?php

namespace App\Http\Controllers\Api\ReportingAudit;

use App\Application\ReportingAudit\Reports\Services\ExportService;
use App\Application\ReportingAudit\Reports\UseCases\ListStockMovementReportUseCase;
use App\Application\Support\ApiResponse;
use App\Application\Support\AuditLogger;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReportingAudit\Report\StockMovementReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MovementReportController extends Controller
{
    public function __construct(
        private readonly ListStockMovementReportUseCase $movementReport,
        private readonly AuditLogger $auditLogger,
        private readonly ExportService $exportService,
    ) {
    }

    public function index(StockMovementReportRequest $request): JsonResponse
    {
        $records = $this->movementReport->execute($request->validated());

        return ApiResponse::success(
            $records->items(),
            'Stock movement report retrieved successfully.',
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

    public function export(StockMovementReportRequest $request): StreamedResponse
    {
        $validated = $request->validated();
        $format = strtolower($validated['format'] ?? 'csv');
        $filters = collect($validated)->except('format')->toArray();

        $rows = $this->movementReport->exportRows($filters);
        $filename = 'stock-movements-'.now()->format('Ymd_His');

        // Log export action
        $this->auditLogger->log(
            userId: (int) $request->user()->id,
            moduleName: 'ReportingAudit',
            entityName: 'StockMovementReport',
            entityId: 0,
            action: AuditAction::Export,
            newValues: ['filters' => $filters, 'filename' => $filename, 'format' => $format],
        );

        $headers = [
            'id',
            'movement_datetime',
            'product_id',
            'stock_item_id',
            'movement_type',
            'reference_table',
            'reference_id',
            'qty_in',
            'qty_out',
            'from_status',
            'to_status',
            'performed_by',
            'remarks',
        ];

        return $this->exportService->export(
            rows: $rows,
            headers: $headers,
            filename: $filename,
            format: $format,
        );
    }
}
