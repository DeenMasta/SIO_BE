<?php

namespace App\Http\Controllers\Api\PurchasingInbound;

use App\Application\Contracts\Repositories\StockInRepository;
use App\Application\PurchasingInbound\StockIn\UseCases\ListStockInsUseCase;
use App\Application\PurchasingInbound\StockIn\UseCases\PostStockInUseCase;
use App\Application\Support\ApiResponse;
use App\Application\Support\DocumentNumberGenerator;
use App\Application\Support\AuditLogger;
use App\Application\ReportingAudit\Reports\Services\ExportService;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PurchasingInbound\StockIn\ExportStockInRequest;
use App\Http\Requests\Api\PurchasingInbound\StockIn\PostStockInRequest;
use App\Http\Resources\Api\PurchasingInbound\StockInResource;
use App\Models\StockIn;
use App\Models\StockItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockInController extends Controller
{
    public function __construct(
        private readonly ListStockInsUseCase $listStockIns,
        private readonly PostStockInUseCase $postStockIn,
        private readonly StockInRepository $stockIns,
        private readonly DocumentNumberGenerator $documentNumberGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly ExportService $exportService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockIn::class);

        $stockIns = $this->listStockIns->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            StockInResource::collection($stockIns->items()),
            'Stock in records retrieved successfully.',
            meta: [
                'pagination' => [
                    'current_page' => $stockIns->currentPage(),
                    'per_page' => $stockIns->perPage(),
                    'total' => $stockIns->total(),
                    'last_page' => $stockIns->lastPage(),
                ],
            ],
        );
    }

    public function store(PostStockInRequest $request): JsonResponse
    {
        $this->authorize('create', StockIn::class);

        $payload = $request->validated();
        $payload['stock_in_pic_id'] = (int) $request->user()->id;
        $payload['stock_in_number'] = trim((string) ($payload['stock_in_number'] ?? '')) !== ''
            ? trim((string) $payload['stock_in_number'])
            : $this->documentNumberGenerator->generateStockInNumber();

        $stockIn = $this->postStockIn->execute($payload);

        return ApiResponse::success(new StockInResource($stockIn), 'Stock in received successfully.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $stockIn = $this->stockIns->findOrFail($id);
        $this->authorize('view', $stockIn);

        return ApiResponse::success(new StockInResource($stockIn), 'Stock in retrieved successfully.');
    }

    public function export(ExportStockInRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', StockIn::class);

        $validated = $request->validated();
        $format = strtolower((string) ($validated['format'] ?? 'csv'));
        $filters = collect($validated)->except('format')->toArray();

        $rows = $this->exportRows($filters);
        $filename = 'stock-ins-'.now()->format('Ymd_His');

        $this->auditLogger->log(
            userId: (int) $request->user()->id,
            moduleName: 'PurchasingInbound',
            entityName: 'StockInExport',
            entityId: 0,
            action: AuditAction::Export,
            newValues: ['filters' => $filters, 'filename' => $filename, 'format' => $format],
        );

        return $this->exportService->export(
            rows: $rows,
            headers: [
                'stock_in_number',
                'stock_in_date',
                'po_number',
                'supplier_code',
                'supplier_name',
                'status',
                'line_count',
                'total_received_qty',
                'remarks',
            ],
            filename: $filename,
            format: $format,
        );
    }

    /**
     * Returns all PENDING QC stock items that belong to the given stock-in session.
     * Used by the QC module to auto-populate the QC checklist.
     */
    public function pendingQcItems(int $id): JsonResponse
    {
        $stockIn = $this->stockIns->findOrFail($id);
        $this->authorize('view', $stockIn);

        $items = StockItem::query()
            ->with(['product.conditions', 'product.accessories'])
            ->whereHas('stockInLine', fn ($q) => $q->where('stock_in_id', $id))
            ->where('qc_status', StockItemQcStatus::Pending->value)
            ->orderBy('id')
            ->get();

        $mapped = $items->map(fn (StockItem $item): array => [
            'id'             => $item->id,
            'serial_number'  => $item->serial_number,
            'product_id'     => $item->product_id,
            'product_name'   => $item->product?->product_name,
            'qc_status'      => $item->qc_status?->value,
            'conditions'     => ($item->product?->conditions ?? collect())
                ->map(fn ($c): string => (string) $c->condition_name)
                ->filter(fn (string $v): bool => $v !== '')
                ->values()
                ->toArray(),
            'accessories'    => ($item->product?->accessories ?? collect())
                ->map(fn ($a): string => (string) $a->accessory_name)
                ->filter(fn (string $v): bool => $v !== '')
                ->values()
                ->toArray(),
        ]);

        return ApiResponse::success($mapped, 'Pending QC items for stock-in retrieved successfully.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function exportRows(array $filters): Collection
    {
        $query = DB::table('stock_in as si')
            ->leftJoin('suppliers as s', 's.id', '=', 'si.supplier_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'si.purchase_order_id')
            ->leftJoin('stock_in_lines as sil', 'sil.stock_in_id', '=', 'si.id')
            ->selectRaw('si.stock_in_number')
            ->selectRaw('si.stock_in_date')
            ->selectRaw('po.po_number')
            ->selectRaw('s.supplier_code')
            ->selectRaw('s.supplier_name')
            ->selectRaw("CASE WHEN si.status = 'POSTED' THEN 'RECEIVED' ELSE si.status END as status")
            ->selectRaw('COUNT(sil.id) as line_count')
            ->selectRaw('COALESCE(SUM(sil.received_qty), 0) as total_received_qty')
            ->selectRaw('si.remarks')
            ->groupBy(
                'si.id',
                'si.stock_in_number',
                'si.stock_in_date',
                'po.po_number',
                's.supplier_code',
                's.supplier_name',
                'si.status',
                'si.remarks',
            )
            ->orderByDesc('si.id');

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('si.stock_in_number', 'like', '%'.$search.'%')
                    ->orWhere('s.supplier_code', 'like', '%'.$search.'%')
                    ->orWhere('s.supplier_name', 'like', '%'.$search.'%')
                    ->orWhere('po.po_number', 'like', '%'.$search.'%');
            });
        }

        return $query->get();
    }
}
