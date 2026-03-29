<?php

namespace App\Http\Controllers\Api\SalesOutbound;

use App\Application\SalesOutbound\SalesOrders\UseCases\CreateSaleOrderUseCase;
use App\Application\SalesOutbound\SalesOrders\UseCases\DeleteSaleOrderUseCase;
use App\Application\SalesOutbound\SalesOrders\UseCases\ListSaleOrdersUseCase;
use App\Application\SalesOutbound\SalesOrders\UseCases\UpdateSaleOrderUseCase;
use App\Application\Support\ApiResponse;
use App\Application\Support\AuditLogger;
use App\Application\Support\DocumentNumberGenerator;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Domain\SalesOutbound\Enums\SaleOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SalesOutbound\SaleOrder\StoreSaleOrderRequest;
use App\Http\Requests\Api\SalesOutbound\SaleOrder\UpdateSaleOrderRequest;
use App\Http\Resources\Api\SalesOutbound\SaleOrderResource;
use App\Models\SaleOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SaleOrderController extends Controller
{
    public function __construct(
        private readonly ListSaleOrdersUseCase $listSaleOrders,
        private readonly CreateSaleOrderUseCase $createSaleOrder,
        private readonly UpdateSaleOrderUseCase $updateSaleOrder,
        private readonly DeleteSaleOrderUseCase $deleteSaleOrder,
        private readonly AuditLogger $auditLogger,
        private readonly DocumentNumberGenerator $documentNumberGenerator,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SaleOrder::class);

        $filters = $request->only(['status', 'customer_id']);
        
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
        $payload['created_by'] = (int) $request->user()->id;
        $payload['status'] = $payload['status'] ?? SaleOrderStatus::Draft;
        $payload['so_number'] = trim((string) ($payload['so_number'] ?? '')) !== ''
            ? trim((string) $payload['so_number'])
            : $this->documentNumberGenerator->generateSaleOrderNumber();

        $saleOrder = $this->createSaleOrder->execute($payload);

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

        $updated = $this->updateSaleOrder->execute($payload);

        return ApiResponse::success(new SaleOrderResource($updated->load('lines.product')), 'Sales order updated successfully.');
    }

    public function destroy(SaleOrder $saleOrder): JsonResponse
    {
        $this->authorize('create', SaleOrder::class);

        $this->deleteSaleOrder->execute($saleOrder->id);

        return ApiResponse::success(null, 'Sales order deleted successfully.');
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

        return $saleOrder;
    }
}
