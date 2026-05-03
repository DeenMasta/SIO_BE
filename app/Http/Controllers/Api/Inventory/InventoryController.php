<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Application\Inventory\UseCases\GetInventoryDetailUseCase;
use App\Application\Inventory\UseCases\ListInventoriesUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Inventory\InventoryQueryRequest;
use App\Http\Resources\Api\Inventory\InventoryResource;
use App\Http\Resources\Api\Inventory\InventorySerialResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

final class InventoryController extends Controller
{
    public function __construct(
        private readonly ListInventoriesUseCase $listInventories,
        private readonly GetInventoryDetailUseCase $getInventoryDetail,
    ) {
    }

    public function index(InventoryQueryRequest $request): JsonResponse
    {
        $records = $this->listInventories->execute($request->validated());

        return ApiResponse::paginated(
            $records,
            InventoryResource::collection($records->items()),
            'Inventories retrieved successfully.',
        );
    }

    public function show(Product $product, InventoryQueryRequest $request): JsonResponse
    {
        $detail = $this->getInventoryDetail->execute([
            ...$request->validated(),
            'product_id' => (int) $product->id,
        ]);

        $serials = $detail['available_serials'];

        return ApiResponse::success(
            [
                'inventory' => new InventoryResource($detail['inventory']),
                'available_serials' => $serials !== null
                    ? InventorySerialResource::collection($serials->items())
                    : [],
            ],
            'Inventory retrieved successfully.',
            meta: $serials !== null ? [
                'available_serials_pagination' => [
                    'current_page' => $serials->currentPage(),
                    'per_page' => $serials->perPage(),
                    'total' => $serials->total(),
                    'last_page' => $serials->lastPage(),
                ],
            ] : [],
        );
    }
}
