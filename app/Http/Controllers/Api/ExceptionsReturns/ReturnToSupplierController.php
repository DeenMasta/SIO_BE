<?php

namespace App\Http\Controllers\Api\ExceptionsReturns;

use App\Application\Contracts\Repositories\ReturnToSupplierRepository;
use App\Application\ExceptionsReturns\ReturnToSuppliers\UseCases\CancelReturnToSupplierUseCase;
use App\Application\ExceptionsReturns\ReturnToSuppliers\UseCases\CreateReturnToSupplierUseCase;
use App\Application\ExceptionsReturns\ReturnToSuppliers\UseCases\ListReturnToSuppliersUseCase;
use App\Application\Support\ApiResponse;
use App\Application\Support\AuditLogger;
use App\Application\ReportingAudit\Reports\Services\ExportService;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExceptionsReturns\CancelExceptionTransactionRequest;
use App\Http\Requests\Api\ExceptionsReturns\ReturnToSupplier\ExportReturnToSupplierRequest;
use App\Http\Requests\Api\ExceptionsReturns\ReturnToSupplier\StoreReturnToSupplierRequest;
use App\Http\Resources\Api\ExceptionsReturns\ReturnToSupplierResource;
use App\Models\ReturnToSupplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReturnToSupplierController extends Controller
{
    public function __construct(
        private readonly ListReturnToSuppliersUseCase $listReturns,
        private readonly CreateReturnToSupplierUseCase $createReturn,
        private readonly CancelReturnToSupplierUseCase $cancelReturn,
        private readonly ReturnToSupplierRepository $returns,
        private readonly AuditLogger $auditLogger,
        private readonly ExportService $exportService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ReturnToSupplier::class);

        $records = $this->listReturns->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            ReturnToSupplierResource::collection($records->items()),
            'Return to supplier records retrieved successfully.',
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

    public function store(StoreReturnToSupplierRequest $request): JsonResponse
    {
        $this->authorize('create', ReturnToSupplier::class);

        $payload = $request->validated();
        $payload['created_by'] = (int) $request->user()->id;

        $record = $this->createReturn->execute($payload);

        return ApiResponse::success(new ReturnToSupplierResource($record), 'Return to supplier posted successfully.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $record = $this->returns->findOrFail($id);
        $this->authorize('view', $record);

        return ApiResponse::success(new ReturnToSupplierResource($record), 'Return to supplier retrieved successfully.');
    }

    public function export(ExportReturnToSupplierRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', ReturnToSupplier::class);

        $validated = $request->validated();
        $format = strtolower((string) ($validated['format'] ?? 'csv'));
        $filters = collect($validated)->except('format')->toArray();

        $rows = $this->exportRows($filters);
        $filename = 'return-to-suppliers-'.now()->format('Ymd_His');

        $this->auditLogger->log(
            userId: (int) $request->user()->id,
            moduleName: 'ExceptionsReturns',
            entityName: 'ReturnToSupplierExport',
            entityId: 0,
            action: AuditAction::Export,
            newValues: ['filters' => $filters, 'filename' => $filename, 'format' => $format],
        );

        return $this->exportService->export(
            rows: $rows,
            headers: [
                'rts_transaction_number',
                'return_date',
                'supplier_name',
                'stock_in_number',
                'status',
                'line_count',
                'total_return_qty',
                'remarks',
            ],
            filename: $filename,
            format: $format,
        );
    }

    public function cancel(CancelExceptionTransactionRequest $request, int $id): JsonResponse
    {
        $record = $this->returns->findOrFail($id);
        $this->authorize('create', ReturnToSupplier::class);

        $cancelled = $this->cancelReturn->execute([
            'return_to_supplier_id' => $id,
            'remarks' => $request->validated('remarks'),
            'cancelled_by' => (int) $request->user()->id,
        ]);

        return ApiResponse::success(new ReturnToSupplierResource($cancelled), 'Return to supplier cancelled successfully.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function exportRows(array $filters): Collection
    {
        $query = DB::table('return_to_supplier as rts')
            ->leftJoin('suppliers as s', 's.id', '=', 'rts.supplier_id')
            ->leftJoin('stock_in as si', 'si.id', '=', 'rts.stock_in_id')
            ->leftJoin('return_to_supplier_lines as rtsl', 'rtsl.return_to_supplier_id', '=', 'rts.id')
            ->selectRaw('rts.rts_transaction_number')
            ->selectRaw('rts.return_date')
            ->selectRaw('s.supplier_name')
            ->selectRaw('si.stock_in_number')
            ->selectRaw('rts.status')
            ->selectRaw('COUNT(rtsl.id) as line_count')
            ->selectRaw('COALESCE(SUM(rtsl.qty), 0) as total_return_qty')
            ->selectRaw('rts.remarks')
            ->groupBy(
                'rts.id',
                'rts.rts_transaction_number',
                'rts.return_date',
                's.supplier_name',
                'si.stock_in_number',
                'rts.status',
                'rts.remarks',
            )
            ->orderByDesc('rts.id');

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('rts.rts_transaction_number', 'like', '%'.$search.'%')
                    ->orWhere('s.supplier_name', 'like', '%'.$search.'%')
                    ->orWhere('si.stock_in_number', 'like', '%'.$search.'%');
            });
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('rts.return_date', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('rts.return_date', '<=', (string) $filters['date_to']);
        }
        if (! empty($filters['status'])) {
            $query->where('rts.status', (string) $filters['status']);
        }

        return $query->get();
    }
}
