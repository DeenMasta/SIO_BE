<?php

namespace App\Http\Controllers\Api\ReportingAudit;

use App\Application\ReportingAudit\Reports\UseCases\ListStockMovementReportUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReportingAudit\Report\StockMovementReportRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MovementReportController extends Controller
{
    public function __construct(private readonly ListStockMovementReportUseCase $movementReport)
    {
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
        $rows = $this->movementReport->exportRows($request->validated());
        $filename = 'stock-movements-'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['id', 'movement_datetime', 'product_id', 'stock_item_id', 'movement_type', 'reference_table', 'reference_id', 'qty_in', 'qty_out', 'from_status', 'to_status', 'performed_by', 'remarks']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    (int) $row->id,
                    $row->movement_datetime?->toISOString(),
                    (int) $row->product_id,
                    $row->stock_item_id,
                    is_object($row->movement_type) ? $row->movement_type->value : $row->movement_type,
                    $row->reference_table,
                    (int) $row->reference_id,
                    (int) $row->qty_in,
                    (int) $row->qty_out,
                    $row->from_status,
                    $row->to_status,
                    (int) $row->performed_by,
                    $row->remarks,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
