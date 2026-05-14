<?php

namespace App\Http\Controllers\Api\SalesOutbound;

use App\Application\SalesOutbound\SalesOrders\UseCases\CreateSaleOrderUseCase;
use App\Application\SalesOutbound\SalesOrders\UseCases\DeleteSaleOrderUseCase;
use App\Application\SalesOutbound\SalesOrders\UseCases\ListSaleOrdersUseCase;
use App\Application\SalesOutbound\SalesOrders\UseCases\UpdateSaleOrderUseCase;
use App\Application\Support\ApiResponse;
use App\Application\Support\AuditLogger;
use App\Application\Support\DocumentNumberGenerator;
use App\Application\Support\UserNotificationService;
use App\Application\ReportingAudit\Reports\Services\ExportService;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Domain\SalesOutbound\Enums\SaleOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SalesOutbound\SaleOrder\ExportSaleOrderRequest;
use App\Http\Requests\Api\SalesOutbound\SaleOrder\StoreSaleOrderRequest;
use App\Http\Requests\Api\SalesOutbound\SaleOrder\UpdateSaleOrderRequest;
use App\Http\Resources\Api\SalesOutbound\SaleOrderResource;
use App\Models\SaleOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SaleOrderController extends Controller
{
    public function __construct(
        private readonly ListSaleOrdersUseCase $listSaleOrders,
        private readonly CreateSaleOrderUseCase $createSaleOrder,
        private readonly UpdateSaleOrderUseCase $updateSaleOrder,
        private readonly DeleteSaleOrderUseCase $deleteSaleOrder,
        private readonly AuditLogger $auditLogger,
        private readonly DocumentNumberGenerator $documentNumberGenerator,
        private readonly ExportService $exportService,
        private readonly UserNotificationService $userNotificationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SaleOrder::class);

        $filters = $request->only(['status', 'customer_id', 'q']);

        $saleOrders = $this->listSaleOrders->execute([
            'per_page' => (int) $request->integer('per_page', 15),
            'filters' => $filters,
        ]);

        return ApiResponse::success(
            SaleOrderResource::collection($saleOrders->items()),
            'Sales orders retrieved successfully.',
            meta: [
                'pagination' => [
                    'current_page' => $saleOrders->currentPage(),
                    'per_page' => $saleOrders->perPage(),
                    'total' => $saleOrders->total(),
                    'last_page' => $saleOrders->lastPage(),
                ],
            ],
        );
    }

    public function store(StoreSaleOrderRequest $request): JsonResponse
    {
        $this->authorize('create', SaleOrder::class);

        $payload = $request->validated();
        $userId = (int) $request->user()->id;
        $payload['created_by'] = $userId;
        $payload['status'] = $payload['status'] ?? SaleOrderStatus::Draft;
        $payload['so_number'] = trim((string) ($payload['so_number'] ?? '')) !== ''
            ? trim((string) $payload['so_number'])
            : $this->documentNumberGenerator->generateSaleOrderNumber();

        $saleOrder = $this->createSaleOrder->execute($payload);

        $this->userNotificationService->notifyAllActiveUsers(
            eventType: 'sale-order.created',
            title: 'Sales order created',
            message: sprintf('Sales order %s was created.', $saleOrder->so_number),
            data: [
                'sale_order_id' => (int) $saleOrder->id,
                'so_number' => $saleOrder->so_number,
                'status' => $saleOrder->status?->value,
            ],
            exceptUserId: $userId,
            level: 'info',
        );

        return ApiResponse::success(new SaleOrderResource($saleOrder), 'Sales order created successfully.', 201);
    }

    public function show(SaleOrder $saleOrder): JsonResponse
    {
        $this->authorize('view', $saleOrder);

        // Load lines, product, and dispatched items (with their stock items) to get serial numbers
        $saleOrder->load(['lines.product', 'lines.dispatchedItems.stockItem']);

        return ApiResponse::success(new SaleOrderResource($saleOrder), 'Sales order retrieved successfully.');
    }

    public function update(UpdateSaleOrderRequest $request, SaleOrder $saleOrder): JsonResponse
    {
        $this->authorize('create', SaleOrder::class);

        $payload = $request->validated();
        $payload['id'] = $saleOrder->id;
        $userId = (int) $request->user()->id;

        $updated = $this->updateSaleOrder->execute($payload);

        $this->userNotificationService->notifyAllActiveUsers(
            eventType: 'sale-order.updated',
            title: 'Sales order updated',
            message: sprintf('Sales order %s was updated.', $updated->so_number),
            data: [
                'sale_order_id' => (int) $updated->id,
                'so_number' => $updated->so_number,
                'status' => $updated->status?->value,
            ],
            exceptUserId: $userId,
            level: 'info',
        );

        return ApiResponse::success(new SaleOrderResource($updated->load('lines.product')), 'Sales order updated successfully.');
    }

    public function destroy(SaleOrder $saleOrder): JsonResponse
    {
        $this->authorize('create', SaleOrder::class);

        $userId = (int) request()->user()->id;
        $saleOrderId = (int) $saleOrder->id;
        $soNumber = $saleOrder->so_number;
        $status = $saleOrder->status?->value;

        $this->deleteSaleOrder->execute($saleOrder->id);

        $this->userNotificationService->notifyAllActiveUsers(
            eventType: 'sale-order.deleted',
            title: 'Sales order deleted',
            message: sprintf('Sales order %s was deleted.', $soNumber),
            data: [
                'sale_order_id' => $saleOrderId,
                'so_number' => $soNumber,
                'status' => $status,
            ],
            exceptUserId: $userId,
            level: 'warning',
        );

        return ApiResponse::success(null, 'Sales order deleted successfully.');
    }

    public function export(ExportSaleOrderRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', SaleOrder::class);

        $validated = $request->validated();
        $format = strtolower((string) ($validated['format'] ?? 'csv'));
        $filters = collect($validated)->except('format')->toArray();

        $rows = $this->exportRows($filters);
        $filename = 'sale-orders-'.now()->format('Ymd_His');

        $this->auditLogger->log(
            userId: (int) $request->user()->id,
            moduleName: 'SalesOutbound',
            entityName: 'SaleOrderExport',
            entityId: 0,
            action: AuditAction::Export,
            newValues: ['filters' => $filters, 'filename' => $filename, 'format' => $format],
        );

        return $this->exportService->export(
            rows: $rows,
            headers: [
                'so_number',
                'so_date',
                'expected_delivery_date',
                'invoice_number',
                'customer_name',
                'status',
                'line_count',
                'ordered_qty',
                'fulfilled_qty',
                'remaining_qty',
                'total_amount',
                'remarks',
            ],
            filename: $filename,
            format: $format,
        );
    }

    public function confirm(SaleOrder $saleOrder, Request $request): JsonResponse
    {
        $this->authorize('create', SaleOrder::class);

        $updated = $this->transitionStatus(
            $saleOrder,
            SaleOrderStatus::Confirmed,
            [SaleOrderStatus::Draft],
            'confirm',
            (int) $request->user()->id,
            AuditAction::Update,
        );

        return ApiResponse::success(new SaleOrderResource($updated->load('lines.product')), 'Sales order confirmed successfully.');
    }

    public function cancel(SaleOrder $saleOrder, Request $request): JsonResponse
    {
        $this->authorize('create', SaleOrder::class);

        $updated = $this->transitionStatus(
            $saleOrder,
            SaleOrderStatus::Cancelled,
            [SaleOrderStatus::Draft, SaleOrderStatus::Confirmed],
            'cancel',
            (int) $request->user()->id,
            AuditAction::Cancel,
        );

        return ApiResponse::success(new SaleOrderResource($updated->load('lines.product')), 'Sales order cancelled successfully.');
    }

    /**
     * @param  array<int, SaleOrderStatus>  $allowedFrom
     */
    private function transitionStatus(
        SaleOrder $saleOrder,
        SaleOrderStatus $toStatus,
        array $allowedFrom,
        string $transition,
        int $userId,
        AuditAction $auditAction,
    ): SaleOrder {
        $currentStatus = $saleOrder->status;

        if (! in_array($currentStatus, $allowedFrom, true)) {
            throw ValidationException::withMessages([
                'status' => [sprintf('Sales order cannot %s from %s status.', $transition, $currentStatus?->value ?? 'UNKNOWN')],
            ]);
        }

        $saleOrder->status = $toStatus;
        $saleOrder->save();

        $this->auditLogger->log(
            userId: $userId,
            moduleName: 'SalesOutbound',
            entityName: 'SaleOrder',
            entityId: (int) $saleOrder->id,
            action: $auditAction,
            oldValues: ['status' => $currentStatus?->value],
            newValues: ['status' => $toStatus->value, 'transition' => $transition],
        );

        $this->userNotificationService->notifyAllActiveUsers(
            eventType: 'sale-order.status-changed',
            title: 'Sales order '.$transition,
            message: sprintf('Sales order %s is now %s.', $saleOrder->so_number, $toStatus->value),
            data: [
                'sale_order_id' => (int) $saleOrder->id,
                'so_number' => $saleOrder->so_number,
                'status' => $toStatus->value,
                'transition' => $transition,
            ],
            exceptUserId: $userId,
            level: $toStatus === SaleOrderStatus::Cancelled ? 'warning' : 'info',
        );

        return $saleOrder;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function exportRows(array $filters): Collection
    {
        $query = DB::table('sale_orders as so')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('sale_order_lines as sol', 'sol.sale_order_id', '=', 'so.id')
            ->selectRaw('so.so_number')
            ->selectRaw('so.so_date')
            ->selectRaw('so.expected_delivery_date')
            ->selectRaw('so.invoice_number')
            ->selectRaw('c.customer_name')
            ->selectRaw('so.status')
            ->selectRaw('COUNT(sol.id) as line_count')
            ->selectRaw('COALESCE(SUM(sol.ordered_qty), 0) as ordered_qty')
            ->selectRaw('COALESCE(SUM(sol.fulfilled_qty), 0) as fulfilled_qty')
            ->selectRaw('COALESCE(SUM(sol.ordered_qty - sol.fulfilled_qty), 0) as remaining_qty')
            ->selectRaw('COALESCE(SUM(sol.subtotal), 0) as total_amount')
            ->selectRaw('so.remarks')
            ->groupBy(
                'so.id',
                'so.so_number',
                'so.so_date',
                'so.expected_delivery_date',
                'so.invoice_number',
                'c.customer_name',
                'so.status',
                'so.remarks',
            )
            ->orderByDesc('so.id');

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('so.so_number', 'like', '%'.$search.'%')
                    ->orWhere('so.invoice_number', 'like', '%'.$search.'%')
                    ->orWhere('c.customer_name', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['status'])) {
            $query->where('so.status', (string) $filters['status']);
        }

        return $query->get()->map(function (object $row): object {
            $row->total_amount = number_format((float) $row->total_amount, 2, '.', '');

            return $row;
        });
    }
}
