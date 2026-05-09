<?php

namespace App\Http\Controllers\Api\ExceptionsReturns;

use App\Application\Contracts\Repositories\RepairRepository;
use App\Application\ExceptionsReturns\Repairs\UseCases\CreateRepairUseCase;
use App\Application\ExceptionsReturns\Repairs\UseCases\ListRepairsUseCase;
use App\Application\ExceptionsReturns\Repairs\UseCases\UpdateRepairStatusUseCase;
use App\Application\Support\ApiResponse;
use App\Application\Support\AuditLogger;
use App\Application\ReportingAudit\Reports\Services\ExportService;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExceptionsReturns\Repair\ExportRepairRequest;
use App\Http\Requests\Api\ExceptionsReturns\Repair\StoreRepairRequest;
use App\Http\Requests\Api\ExceptionsReturns\Repair\UpdateRepairStatusRequest;
use App\Http\Resources\Api\ExceptionsReturns\RepairResource;
use App\Models\Repair;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RepairController extends Controller
{
    public function __construct(
        private readonly ListRepairsUseCase $listRepairs,
        private readonly CreateRepairUseCase $createRepair,
        private readonly UpdateRepairStatusUseCase $updateRepairStatus,
        private readonly RepairRepository $repairs,
        private readonly AuditLogger $auditLogger,
        private readonly ExportService $exportService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Repair::class);

        $records = $this->listRepairs->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            RepairResource::collection($records->items()),
            'Repairs retrieved successfully.',
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

    public function store(StoreRepairRequest $request): JsonResponse
    {
        $this->authorize('create', Repair::class);

        $payload = $request->validated();
        $payload['created_by'] = (int) $request->user()->id;

        $repair = $this->createRepair->execute($payload);

        return ApiResponse::success(new RepairResource($repair), 'Repair created successfully.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $repair = $this->repairs->findOrFail($id);
        $this->authorize('view', $repair);

        return ApiResponse::success(new RepairResource($repair), 'Repair retrieved successfully.');
    }

    public function export(ExportRepairRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', Repair::class);

        $validated = $request->validated();
        $format = strtolower((string) ($validated['format'] ?? 'csv'));
        $filters = collect($validated)->except('format')->toArray();

        $rows = $this->exportRows($filters);
        $filename = 'repairs-'.now()->format('Ymd_His');

        $this->auditLogger->log(
            userId: (int) $request->user()->id,
            moduleName: 'ExceptionsReturns',
            entityName: 'RepairExport',
            entityId: 0,
            action: AuditAction::Export,
            newValues: ['filters' => $filters, 'filename' => $filename, 'format' => $format],
        );

        return $this->exportService->export(
            rows: $rows,
            headers: [
                'repair_transaction_number',
                'repair_date',
                'repair_flow',
                'serial_number',
                'product_code',
                'product_name',
                'customer_name',
                'repair_status',
                'returned_to_customer_date',
                'return_tracking_number',
                'issue_description',
                'remarks',
            ],
            filename: $filename,
            format: $format,
        );
    }

    public function updateStatus(UpdateRepairStatusRequest $request, int $id): JsonResponse
    {
        $repair = $this->repairs->findOrFail($id);
        $this->authorize('update', $repair);

        $updated = $this->updateRepairStatus->execute([
            'repair' => $repair,
            ...$request->validated(),
            'updated_by' => (int) $request->user()->id,
        ]);

        return ApiResponse::success(new RepairResource($updated), 'Repair status updated successfully.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function exportRows(array $filters): Collection
    {
        $query = DB::table('repairs as r')
            ->leftJoin('stock_items as si', 'si.id', '=', 'r.stock_item_id')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('customers as c', 'c.id', '=', 'r.customer_id')
            ->selectRaw('r.repair_transaction_number')
            ->selectRaw('r.repair_date')
            ->selectRaw('r.repair_flow')
            ->selectRaw('si.serial_number')
            ->selectRaw('p.product_code')
            ->selectRaw('p.product_name')
            ->selectRaw('c.customer_name')
            ->selectRaw('r.repair_status')
            ->selectRaw('r.returned_to_customer_date')
            ->selectRaw('r.return_tracking_number')
            ->selectRaw('r.issue_description')
            ->selectRaw('r.remarks')
            ->orderByDesc('r.id');

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('r.repair_transaction_number', 'like', '%'.$search.'%')
                    ->orWhere('r.repair_flow', 'like', '%'.$search.'%')
                    ->orWhere('r.repair_status', 'like', '%'.$search.'%')
                    ->orWhere('r.issue_description', 'like', '%'.$search.'%')
                    ->orWhere('r.return_tracking_number', 'like', '%'.$search.'%')
                    ->orWhere('si.serial_number', 'like', '%'.$search.'%')
                    ->orWhere('p.product_code', 'like', '%'.$search.'%')
                    ->orWhere('p.product_name', 'like', '%'.$search.'%')
                    ->orWhere('c.customer_name', 'like', '%'.$search.'%');
            });
        }
        if (! empty($filters['status'])) {
            $query->where('r.repair_status', (string) $filters['status']);
        }
        if (! empty($filters['repair_flow'])) {
            $query->where('r.repair_flow', (string) $filters['repair_flow']);
        }

        return $query->get();
    }
}
