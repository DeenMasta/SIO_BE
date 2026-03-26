<?php

namespace App\Http\Controllers\Api\PurchasingInbound;

use App\Application\PurchasingInbound\PurchaseOrders\UseCases\CreatePurchaseOrderUseCase;
use App\Application\PurchasingInbound\PurchaseOrders\UseCases\ListPurchaseOrdersUseCase;
use App\Application\Support\ApiResponse;
use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PurchasingInbound\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Resources\Api\PurchasingInbound\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly ListPurchaseOrdersUseCase $listPurchaseOrders,
        private readonly CreatePurchaseOrderUseCase $createPurchaseOrder,
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
}
