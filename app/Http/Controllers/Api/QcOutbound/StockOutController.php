<?php

namespace App\Http\Controllers\Api\QcOutbound;

use App\Application\Contracts\Repositories\StockOutRepository;
use App\Application\QcOutbound\StockOut\UseCases\ListStockOutsUseCase;
use App\Application\QcOutbound\StockOut\UseCases\PostStockOutUseCase;
use App\Application\Support\ApiResponse;
use App\Application\Support\AuditLogger;
use App\Application\ReportingAudit\Reports\Services\ExportService;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\QcOutbound\StockOut\ExportStockOutRequest;
use App\Http\Requests\Api\QcOutbound\StockOut\StockOutSerialOptionsRequest;
use App\Http\Requests\Api\QcOutbound\StockOut\StoreStockOutRequest;
use App\Http\Resources\Api\QcOutbound\StockOutResource;
use App\Models\StockItem;
use App\Models\StockOut;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockOutController extends Controller
{
    public function __construct(
        private readonly ListStockOutsUseCase $listStockOuts,
        private readonly PostStockOutUseCase $postStockOut,
        private readonly StockOutRepository $stockOuts,
        private readonly AuditLogger $auditLogger,
        private readonly ExportService $exportService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockOut::class);

        $records = $this->listStockOuts->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            StockOutResource::collection($records->items()),
            'Stock out records retrieved successfully.',
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

    public function store(StoreStockOutRequest $request): JsonResponse
    {
        $this->authorize('create', StockOut::class);

        $payload = $request->validated();
        $payload['pic_id'] = (int) $request->user()->id;

        $result = $this->postStockOut->execute($payload);

        return ApiResponse::success(
            new StockOutResource($result['stock_out']),
            $result['replayed'] ? 'Stock out request already processed.' : 'Stock out posted successfully.',
            $result['replayed'] ? 200 : 201,
        );
    }

    public function show(int $id): JsonResponse
    {
        $stockOut = $this->stockOuts->findOrFail($id);
        $this->authorize('view', $stockOut);

        return ApiResponse::success(new StockOutResource($stockOut), 'Stock out retrieved successfully.');
    }

    public function export(ExportStockOutRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', StockOut::class);

        $validated = $request->validated();
        $format = strtolower((string) ($validated['format'] ?? 'csv'));
        $filters = collect($validated)->except('format')->toArray();

        $rows = $this->exportRows($filters);
        $filename = 'stock-outs-'.now()->format('Ymd_His');

        $this->auditLogger->log(
            userId: (int) $request->user()->id,
            moduleName: 'QcOutbound',
            entityName: 'StockOutExport',
            entityId: 0,
            action: AuditAction::Export,
            newValues: ['filters' => $filters, 'filename' => $filename, 'format' => $format],
        );

        return $this->exportService->export(
            rows: $rows,
            headers: [
                'stock_out_number',
                'stock_out_date',
                'so_number',
                'customer_name',
                'status',
                'line_count',
                'total_qty_out',
                'remarks',
            ],
            filename: $filename,
            format: $format,
        );
    }

    public function serialOptions(StockOutSerialOptionsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', StockOut::class);

        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 200);

        $records = StockItem::query()
            ->select(['id', 'product_id', 'serial_number'])
            ->where('is_available', true)
            ->where('current_status', 'IN_STOCK')
            ->where('qc_status', 'PASSED')
            ->when(! empty($filters['product_id']), function (Builder $query) use ($filters): void {
                $query->where('product_id', (int) $filters['product_id']);
            })
            ->when(! empty($filters['query']), function (Builder $query) use ($filters): void {
                $query->where('serial_number', 'like', '%'.(string) $filters['query'].'%');
            })
            ->orderByDesc('id')
            ->paginate($perPage > 0 ? $perPage : 200);

        return ApiResponse::success(
            $records->items(),
            'Registered serial options retrieved successfully.',
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

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function exportRows(array $filters): Collection
    {
        $query = DB::table('stock_out as so')
            ->leftJoin('sale_orders as sale', 'sale.id', '=', 'so.sale_order_id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('stock_out_lines as sol', 'sol.stock_out_id', '=', 'so.id')
            ->selectRaw('so.stock_out_number')
            ->selectRaw('so.stock_out_date')
            ->selectRaw('sale.so_number')
            ->selectRaw('c.customer_name')
            ->selectRaw('so.status')
            ->selectRaw('COUNT(sol.id) as line_count')
            ->selectRaw('COALESCE(SUM(sol.qty), 0) as total_qty_out')
            ->selectRaw('so.remarks')
            ->groupBy(
                'so.id',
                'so.stock_out_number',
                'so.stock_out_date',
                'sale.so_number',
                'c.customer_name',
                'so.status',
                'so.remarks',
            )
            ->orderByDesc('so.id');

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('so.stock_out_number', 'like', '%'.$search.'%')
                    ->orWhere('sale.so_number', 'like', '%'.$search.'%')
                    ->orWhere('c.customer_name', 'like', '%'.$search.'%')
                    ->orWhere('so.invoice_number', 'like', '%'.$search.'%');
            });
        }

        return $query->get();
    }
}
