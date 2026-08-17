<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Application\Inventory\UseCases\CreateStocktakeUseCase;
use App\Application\Inventory\UseCases\DeleteStocktakeUseCase;
use App\Application\Inventory\UseCases\SubmitStocktakeUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Inventory\Stocktake\StoreStocktakeRequest;
use App\Http\Requests\Api\Inventory\Stocktake\SubmitStocktakeRequest;
use App\Http\Resources\Api\Inventory\StocktakeResource;
use App\Models\Stocktake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StocktakeController extends Controller
{
    public function __construct(
        private readonly CreateStocktakeUseCase $createStocktake,
        private readonly SubmitStocktakeUseCase $submitStocktake,
        private readonly DeleteStocktakeUseCase $deleteStocktake,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Stocktake::class);
        $records = Stocktake::query()->with(['createdByUser', 'submittedByUser'])->latest('id')->paginate((int) $request->integer('per_page', 20));

        return ApiResponse::success(StocktakeResource::collection($records->items()), 'Stocktakes retrieved successfully.', meta: ['pagination' => ['current_page' => $records->currentPage(), 'per_page' => $records->perPage(), 'total' => $records->total(), 'last_page' => $records->lastPage()]]);
    }

    public function store(StoreStocktakeRequest $request): JsonResponse
    {
        $this->authorize('create', Stocktake::class);
        $stocktake = $this->createStocktake->execute([...$request->validated(), 'created_by' => (int) $request->user()->id]);

        return ApiResponse::success(new StocktakeResource($stocktake), 'Stocktake created. Count the snapshot and submit it for review.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $stocktake = $this->stocktakeDetail($id);
        $this->authorize('view', $stocktake);

        return ApiResponse::success(new StocktakeResource($stocktake), 'Stocktake retrieved successfully.');
    }

    public function submit(SubmitStocktakeRequest $request, int $id): JsonResponse
    {
        $stocktake = Stocktake::query()->findOrFail($id);
        $this->authorize('submit', $stocktake);
        $result = $this->submitStocktake->execute([...$request->validated(), 'stocktake_id' => $stocktake->id, 'submitted_by' => (int) $request->user()->id]);

        return ApiResponse::success(new StocktakeResource($result), 'Stocktake submitted. Shortages have been opened as investigation reports.');
    }

    public function destroy(Stocktake $stocktake, Request $request): JsonResponse
    {
        $this->authorize('delete', $stocktake);
        $deletedReports = $this->deleteStocktake->execute([
            'stocktake_id' => (int) $stocktake->id,
            'deleted_by' => (int) $request->user()->id,
        ]);

        $message = $deletedReports > 0
            ? 'Stocktake and its unresolved missing-item reports deleted successfully.'
            : 'Stocktake deleted successfully.';

        return ApiResponse::success(null, $message);
    }

    private function stocktakeDetail(int $id): Stocktake
    {
        return Stocktake::query()->with(['lines.product', 'lines.items', 'missingReports.product', 'missingReports.stockItem', 'missingReports.stockOut', 'missingReports.reportedByUser', 'missingReports.resolvedByUser', 'createdByUser', 'submittedByUser'])->findOrFail($id);
    }
}
