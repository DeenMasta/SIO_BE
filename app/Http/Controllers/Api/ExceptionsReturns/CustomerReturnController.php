<?php

namespace App\Http\Controllers\Api\ExceptionsReturns;

use App\Application\Contracts\Repositories\CustomerReturnRepository;
use App\Application\ExceptionsReturns\CustomerReturns\UseCases\CancelCustomerReturnUseCase;
use App\Application\ExceptionsReturns\CustomerReturns\UseCases\CreateCustomerReturnUseCase;
use App\Application\ExceptionsReturns\CustomerReturns\UseCases\ListCustomerReturnsUseCase;
use App\Application\Support\ApiResponse;
use App\Application\Support\AuditLogger;
use App\Application\ReportingAudit\Reports\Services\ExportService;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExceptionsReturns\CancelExceptionTransactionRequest;
use App\Http\Requests\Api\ExceptionsReturns\CustomerReturn\ExportCustomerReturnRequest;
use App\Http\Requests\Api\ExceptionsReturns\CustomerReturn\StoreCustomerReturnRequest;
use App\Http\Resources\Api\ExceptionsReturns\CustomerReturnResource;
use App\Models\CustomerReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerReturnController extends Controller
{
    public function __construct(
        private readonly ListCustomerReturnsUseCase $listReturns,
        private readonly CreateCustomerReturnUseCase $createReturn,
        private readonly CancelCustomerReturnUseCase $cancelReturn,
        private readonly CustomerReturnRepository $returns,
        private readonly AuditLogger $auditLogger,
        private readonly ExportService $exportService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerReturn::class);

        $records = $this->listReturns->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            CustomerReturnResource::collection($records->items()),
            'Customer returns retrieved successfully.',
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

    public function store(StoreCustomerReturnRequest $request): JsonResponse
    {
        $this->authorize('create', CustomerReturn::class);

        $payload = $request->validated();
        $payload['created_by'] = (int) $request->user()->id;

        $record = $this->createReturn->execute($payload);

        return ApiResponse::success(new CustomerReturnResource($record), 'Customer return posted successfully.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $record = $this->returns->findOrFail($id);
        $this->authorize('view', $record);

        return ApiResponse::success(new CustomerReturnResource($record), 'Customer return retrieved successfully.');
    }

    public function export(ExportCustomerReturnRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', CustomerReturn::class);

        $validated = $request->validated();
        $format = strtolower((string) ($validated['format'] ?? 'csv'));
        $filters = collect($validated)->except('format')->toArray();

        $rows = $this->exportRows($filters);
        $filename = 'customer-returns-'.now()->format('Ymd_His');

        $this->auditLogger->log(
            userId: (int) $request->user()->id,
            moduleName: 'ExceptionsReturns',
            entityName: 'CustomerReturnExport',
            entityId: 0,
            action: AuditAction::Export,
            newValues: ['filters' => $filters, 'filename' => $filename, 'format' => $format],
        );

        return $this->exportService->export(
            rows: $rows,
            headers: [
                'return_transaction_number',
                'return_date',
                'original_invoice_number',
                'customer_name',
                'stock_out_number',
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
        $this->authorize('create', CustomerReturn::class);

        $cancelled = $this->cancelReturn->execute([
            'customer_return_id' => $id,
            'remarks' => $request->validated('remarks'),
            'cancelled_by' => (int) $request->user()->id,
        ]);

        return ApiResponse::success(new CustomerReturnResource($cancelled), 'Customer return cancelled successfully.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function exportRows(array $filters): Collection
    {
        $query = DB::table('customer_returns as cr')
            ->leftJoin('customers as c', 'c.id', '=', 'cr.customer_id')
            ->leftJoin('stock_out as so', 'so.id', '=', 'cr.original_stock_out_id')
            ->leftJoin('customer_return_lines as crl', 'crl.customer_return_id', '=', 'cr.id')
            ->selectRaw('cr.return_transaction_number')
            ->selectRaw('cr.return_date')
            ->selectRaw('cr.original_invoice_number')
            ->selectRaw('c.customer_name')
            ->selectRaw('so.stock_out_number')
            ->selectRaw('cr.status')
            ->selectRaw('COUNT(crl.id) as line_count')
            ->selectRaw('COALESCE(SUM(crl.qty), 0) as total_return_qty')
            ->selectRaw('cr.remarks')
            ->groupBy(
                'cr.id',
                'cr.return_transaction_number',
                'cr.return_date',
                'cr.original_invoice_number',
                'c.customer_name',
                'so.stock_out_number',
                'cr.status',
                'cr.remarks',
            )
            ->orderByDesc('cr.id');

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('cr.return_transaction_number', 'like', '%'.$search.'%')
                    ->orWhere('cr.original_invoice_number', 'like', '%'.$search.'%')
                    ->orWhere('c.customer_name', 'like', '%'.$search.'%')
                    ->orWhere('so.stock_out_number', 'like', '%'.$search.'%');
            });
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('cr.return_date', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('cr.return_date', '<=', (string) $filters['date_to']);
        }
        if (! empty($filters['status'])) {
            $query->where('cr.status', (string) $filters['status']);
        }

        return $query->get();
    }
}
