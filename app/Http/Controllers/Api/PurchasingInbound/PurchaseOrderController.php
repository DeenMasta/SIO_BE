<?php

namespace App\Http\Controllers\Api\PurchasingInbound;

use App\Application\PurchasingInbound\PurchaseOrders\UseCases\CreatePurchaseOrderUseCase;
use App\Application\PurchasingInbound\PurchaseOrders\UseCases\DeletePurchaseOrderUseCase;
use App\Application\PurchasingInbound\PurchaseOrders\UseCases\ListPurchaseOrdersUseCase;
use App\Application\PurchasingInbound\PurchaseOrders\UseCases\UpdatePurchaseOrderUseCase;
use App\Application\Support\AuditLogger;
use App\Application\Support\ApiResponse;
use App\Application\Support\DocumentNumberGenerator;
use App\Application\ReportingAudit\Reports\Services\ExportService;
use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PurchasingInbound\PurchaseOrder\ExportPurchaseOrderRequest;
use App\Http\Requests\Api\PurchasingInbound\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Requests\Api\PurchasingInbound\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Http\Resources\Api\PurchasingInbound\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly ListPurchaseOrdersUseCase $listPurchaseOrders,
        private readonly CreatePurchaseOrderUseCase $createPurchaseOrder,
        private readonly UpdatePurchaseOrderUseCase $updatePurchaseOrder,
        private readonly DeletePurchaseOrderUseCase $deletePurchaseOrder,
        private readonly AuditLogger $auditLogger,
        private readonly DocumentNumberGenerator $documentNumberGenerator,
        private readonly ExportService $exportService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $purchaseOrders = $this->listPurchaseOrders->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            PurchaseOrderResource::collection($purchaseOrders->items()),
            'Purchase orders retrieved successfully.',
            meta: [
                'pagination' => [
                    'current_page' => $purchaseOrders->currentPage(),
                    'per_page' => $purchaseOrders->perPage(),
                    'total' => $purchaseOrders->total(),
                    'last_page' => $purchaseOrders->lastPage(),
                ],
            ],
        );
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $payload = $request->validated();
        $payload['created_by'] = (int) $request->user()->id;
        $payload['status'] = $payload['status'] ?? PurchaseOrderStatus::Draft;
        $payload['po_number'] = trim((string) ($payload['po_number'] ?? '')) !== ''
            ? trim((string) $payload['po_number'])
            : $this->documentNumberGenerator->generatePurchaseOrderNumber();

        $purchaseOrder = $this->createPurchaseOrder->execute($payload);

        return ApiResponse::success(new PurchaseOrderResource($purchaseOrder), 'Purchase order created successfully.', 201);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('view', $purchaseOrder);

        return ApiResponse::success(new PurchaseOrderResource($purchaseOrder->load('lines.product')), 'Purchase order retrieved successfully.');
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);

        $payload = $request->validated();
        $payload['id'] = $purchaseOrder->id;

        $updated = $this->updatePurchaseOrder->execute($payload);

        return ApiResponse::success(new PurchaseOrderResource($updated->load('lines.product')), 'Purchase order updated successfully.');
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('delete', $purchaseOrder);

        $this->deletePurchaseOrder->execute($purchaseOrder->id);

        return ApiResponse::success(null, 'Purchase order deleted successfully.');
    }

    public function export(ExportPurchaseOrderRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $validated = $request->validated();
        $format = strtolower((string) ($validated['format'] ?? 'csv'));
        $filters = collect($validated)->except('format')->toArray();

        $rows = $this->exportRows($filters);
        $filename = 'purchase-orders-'.now()->format('Ymd_His');

        $this->auditLogger->log(
            userId: (int) $request->user()->id,
            moduleName: 'PurchasingInbound',
            entityName: 'PurchaseOrderExport',
            entityId: 0,
            action: AuditAction::Export,
            newValues: ['filters' => $filters, 'filename' => $filename, 'format' => $format],
        );

        return $this->exportService->export(
            rows: $rows,
            headers: [
                'po_number',
                'po_date',
                'expected_delivery_date',
                'supplier_code',
                'supplier_name',
                'status',
                'line_count',
                'ordered_qty',
                'received_qty',
                'remaining_qty',
                'total_amount',
                'remarks',
            ],
            filename: $filename,
            format: $format,
        );
    }

    public function issue(PurchaseOrder $purchaseOrder, Request $request): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $updated = $this->transitionStatus(
            $purchaseOrder,
            PurchaseOrderStatus::Issued,
            [PurchaseOrderStatus::Draft],
            'issue',
            (int) $request->user()->id,
            AuditAction::Update,
        );

        return ApiResponse::success(new PurchaseOrderResource($updated->load('lines.product')), 'Purchase order issued successfully.');
    }

    public function complete(PurchaseOrder $purchaseOrder, Request $request): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $updated = $this->transitionStatus(
            $purchaseOrder,
            PurchaseOrderStatus::Completed,
            [PurchaseOrderStatus::Issued],
            'complete',
            (int) $request->user()->id,
            AuditAction::Update,
        );

        return ApiResponse::success(new PurchaseOrderResource($updated->load('lines.product')), 'Purchase order completed successfully.');
    }

    public function cancel(PurchaseOrder $purchaseOrder, Request $request): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $updated = $this->transitionStatus(
            $purchaseOrder,
            PurchaseOrderStatus::Cancelled,
            [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Issued],
            'cancel',
            (int) $request->user()->id,
            AuditAction::Cancel,
        );

        return ApiResponse::success(new PurchaseOrderResource($updated->load('lines.product')), 'Purchase order cancelled successfully.');
    }

    /**
     * @param  array<int, PurchaseOrderStatus>  $allowedFrom
     */
    private function transitionStatus(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderStatus $toStatus,
        array $allowedFrom,
        string $transition,
        int $userId,
        AuditAction $auditAction,
    ): PurchaseOrder {
        $currentStatus = $purchaseOrder->status;

        if (! in_array($currentStatus, $allowedFrom, true)) {
            throw ValidationException::withMessages([
                'status' => [sprintf('Purchase order cannot %s from %s status.', $transition, $currentStatus?->value ?? 'UNKNOWN')],
            ]);
        }

        $purchaseOrder->status = $toStatus;
        $purchaseOrder->save();

        $this->auditLogger->log(
            userId: $userId,
            moduleName: 'PurchasingInbound',
            entityName: 'PurchaseOrder',
            entityId: (int) $purchaseOrder->id,
            action: $auditAction,
            oldValues: ['status' => $currentStatus?->value],
            newValues: ['status' => $toStatus->value, 'transition' => $transition],
        );

        return $purchaseOrder;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function exportRows(array $filters): Collection
    {
        $query = PurchaseOrder::query()
            ->from('purchase_orders', 'po')
            ->leftJoin('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->leftJoin('purchase_order_lines as pol', 'pol.purchase_order_id', '=', 'po.id')
            ->selectRaw('po.po_number')
            ->selectRaw('po.po_date')
            ->selectRaw('po.expected_delivery_date')
            ->selectRaw('s.supplier_code')
            ->selectRaw('s.supplier_name')
            ->selectRaw('po.status')
            ->selectRaw('COUNT(pol.id) as line_count')
            ->selectRaw('COALESCE(SUM(pol.ordered_qty), 0) as ordered_qty')
            ->selectRaw('COALESCE(SUM(pol.received_qty), 0) as received_qty')
            ->selectRaw('COALESCE(SUM(pol.ordered_qty - pol.received_qty), 0) as remaining_qty')
            ->selectRaw('COALESCE(SUM(pol.subtotal), 0) as total_amount')
            ->selectRaw('po.remarks')
            ->groupBy(
                'po.id',
                'po.po_number',
                'po.po_date',
                'po.expected_delivery_date',
                's.supplier_code',
                's.supplier_name',
                'po.status',
                'po.remarks',
            )
            ->orderByDesc('po.id');

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('po.po_number', 'like', '%'.$search.'%')
                    ->orWhere('s.supplier_name', 'like', '%'.$search.'%')
                    ->orWhere('s.supplier_code', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('po.po_date', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('po.po_date', '<=', (string) $filters['date_to']);
        }

        if (! empty($filters['status'])) {
            $query->where('po.status', (string) $filters['status']);
        }

        return $query->get()->map(function (object $row): object {
            $row->total_amount = number_format((float) $row->total_amount, 2, '.', '');

            return $row;
        });
    }
}
