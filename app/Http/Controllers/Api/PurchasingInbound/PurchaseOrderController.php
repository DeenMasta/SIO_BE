<?php

namespace App\Http\Controllers\Api\PurchasingInbound;

use App\Application\PurchasingInbound\PurchaseOrders\UseCases\CreatePurchaseOrderUseCase;
use App\Application\PurchasingInbound\PurchaseOrders\UseCases\ListPurchaseOrdersUseCase;
use App\Application\Support\AuditLogger;
use App\Application\Support\ApiResponse;
use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PurchasingInbound\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Resources\Api\PurchasingInbound\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly ListPurchaseOrdersUseCase $listPurchaseOrders,
        private readonly CreatePurchaseOrderUseCase $createPurchaseOrder,
        private readonly AuditLogger $auditLogger,
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

        $purchaseOrder = $this->createPurchaseOrder->execute($payload);

        return ApiResponse::success(new PurchaseOrderResource($purchaseOrder), 'Purchase order created successfully.', 201);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('view', $purchaseOrder);

        return ApiResponse::success(new PurchaseOrderResource($purchaseOrder->load('lines')), 'Purchase order retrieved successfully.');
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

        return ApiResponse::success(new PurchaseOrderResource($updated->load('lines')), 'Purchase order issued successfully.');
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

        return ApiResponse::success(new PurchaseOrderResource($updated->load('lines')), 'Purchase order completed successfully.');
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

        return ApiResponse::success(new PurchaseOrderResource($updated->load('lines')), 'Purchase order cancelled successfully.');
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
}
