<?php

namespace App\Http\Controllers\Api\PurchasingInbound;

use App\Application\Contracts\Repositories\StockInRepository;
use App\Application\PurchasingInbound\StockIn\UseCases\ListStockInsUseCase;
use App\Application\PurchasingInbound\StockIn\UseCases\PostStockInUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PurchasingInbound\StockIn\PostStockInRequest;
use App\Http\Resources\Api\PurchasingInbound\StockInResource;
use App\Models\StockIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockInController extends Controller
{
    public function __construct(
        private readonly ListStockInsUseCase $listStockIns,
        private readonly PostStockInUseCase $postStockIn,
        private readonly StockInRepository $stockIns,
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

        $stockIn = $this->postStockIn->execute($payload);

        return ApiResponse::success(new StockInResource($stockIn), 'Stock in posted successfully.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $stockIn = $this->stockIns->findOrFail($id);
        $this->authorize('view', $stockIn);

        return ApiResponse::success(new StockInResource($stockIn), 'Stock in retrieved successfully.');
    }
}
