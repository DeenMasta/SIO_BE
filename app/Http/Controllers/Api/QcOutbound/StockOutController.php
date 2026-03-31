<?php

namespace App\Http\Controllers\Api\QcOutbound;

use App\Application\Contracts\Repositories\StockOutRepository;
use App\Application\QcOutbound\StockOut\UseCases\ListStockOutsUseCase;
use App\Application\QcOutbound\StockOut\UseCases\PostStockOutUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\QcOutbound\StockOut\StockOutSerialOptionsRequest;
use App\Http\Requests\Api\QcOutbound\StockOut\StoreStockOutRequest;
use App\Http\Resources\Api\QcOutbound\StockOutResource;
use App\Models\StockItem;
use App\Models\StockOut;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockOutController extends Controller
{
    public function __construct(
        private readonly ListStockOutsUseCase $listStockOuts,
        private readonly PostStockOutUseCase $postStockOut,
        private readonly StockOutRepository $stockOuts,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockOut::class);

        $records = $this->listStockOuts->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            StockOutResource::collection($records->items()),
            'Stock out records retrieved successfully.',
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

    public function store(StoreStockOutRequest $request): JsonResponse
    {
        $this->authorize('create', StockOut::class);

        $payload = $request->validated();
        $payload['pic_id'] = (int) $request->user()->id;

        $result = $this->postStockOut->execute($payload);

        return ApiResponse::success(
            new StockOutResource($result['stock_out']),
            $result['replayed'] ? 'Stock out request already processed.' : 'Stock out posted successfully.',
            $result['replayed'] ? 200 : 201,
        );
    }

    public function show(int $id): JsonResponse
    {
        $stockOut = $this->stockOuts->findOrFail($id);
        $this->authorize('view', $stockOut);

        return ApiResponse::success(new StockOutResource($stockOut), 'Stock out retrieved successfully.');
    }

    public function serialOptions(StockOutSerialOptionsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', StockOut::class);

        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 200);

        $records = StockItem::query()
            ->select(['id', 'product_id', 'serial_number', 'factory_serial_number'])
            ->where('is_available', true)
            ->where('current_status', 'IN_STOCK')
            ->where('qc_status', 'PASSED')
            ->when(! empty($filters['product_id']), function (Builder $query) use ($filters): void {
                $query->where('product_id', (int) $filters['product_id']);
            })
            ->when(! empty($filters['query']), function (Builder $query) use ($filters): void {
                $query->where(function (Builder $search) use ($filters): void {
                    $search->where('serial_number', 'like', '%'.(string) $filters['query'].'%')
                        ->orWhere('factory_serial_number', 'like', '%'.(string) $filters['query'].'%');
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage > 0 ? $perPage : 200);

        return ApiResponse::success(
            $records->items(),
            'Registered serial options retrieved successfully.',
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
}
