<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Application\Inventory\UseCases\CreateInternalStockMovementUseCase;
use App\Application\Inventory\UseCases\ReturnInternalStockMovementUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Inventory\InternalStockMovement\ReturnInternalStockMovementRequest;
use App\Http\Requests\Api\Inventory\InternalStockMovement\StoreInternalStockMovementRequest;
use App\Http\Resources\Api\Inventory\InternalStockMovementResource;
use App\Models\InternalStockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalStockMovementController extends Controller
{
    public function __construct(
        private readonly CreateInternalStockMovementUseCase $createInternalStockMovement,
        private readonly ReturnInternalStockMovementUseCase $returnInternalStockMovement,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InternalStockMovement::class);

        $records = InternalStockMovement::query()
            ->with('lines')
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(
            InternalStockMovementResource::collection($records->items()),
            'Internal stock movements retrieved successfully.',
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

    public function store(StoreInternalStockMovementRequest $request): JsonResponse
    {
        $this->authorize('create', InternalStockMovement::class);

        $payload = $request->validated();
        $payload['created_by'] = (int) $request->user()->id;

        $record = $this->createInternalStockMovement->execute($payload);

        return ApiResponse::success(new InternalStockMovementResource($record), 'Internal stock movement posted successfully.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $record = InternalStockMovement::query()->with('lines')->findOrFail($id);
        $this->authorize('view', $record);

        return ApiResponse::success(new InternalStockMovementResource($record), 'Internal stock movement retrieved successfully.');
    }

    public function returnToStock(ReturnInternalStockMovementRequest $request, int $id): JsonResponse
    {
        $record = InternalStockMovement::query()->findOrFail($id);
        $this->authorize('update', $record);

        $updated = $this->returnInternalStockMovement->execute([
            'internal_stock_movement' => $record,
            ...$request->validated(),
            'created_by' => (int) $request->user()->id,
        ]);

        return ApiResponse::success(new InternalStockMovementResource($updated), 'Internal stock returned to stock successfully.');
    }
}
