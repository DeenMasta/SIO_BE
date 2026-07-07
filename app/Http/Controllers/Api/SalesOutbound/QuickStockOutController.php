<?php

namespace App\Http\Controllers\Api\SalesOutbound;

use App\Application\SalesOutbound\QuickStockOut\UseCases\ConvertQuickStockOutUseCase;
use App\Application\SalesOutbound\QuickStockOut\UseCases\PostQuickStockOutUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SalesOutbound\QuickStockOut\ConvertQuickStockOutRequest;
use App\Http\Requests\Api\SalesOutbound\QuickStockOut\StoreQuickStockOutRequest;
use App\Http\Resources\Api\SalesOutbound\QuickStockOutResource;
use App\Models\QuickStockOut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuickStockOutController extends Controller
{
    public function __construct(
        private readonly PostQuickStockOutUseCase $postQuickStockOut,
        private readonly ConvertQuickStockOutUseCase $convertQuickStockOut,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', QuickStockOut::class);

        $records = QuickStockOut::query()
            ->with(['customer', 'createdBy', 'stockOut.saleOrder', 'convertedSaleOrder', 'lines.product', 'lines.lineItems'])
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(
            QuickStockOutResource::collection($records->items()),
            'Quick stock outs retrieved successfully.',
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

    public function store(StoreQuickStockOutRequest $request): JsonResponse
    {
        $this->authorize('create', QuickStockOut::class);

        $payload = $request->validated();
        $qso = $this->postQuickStockOut->execute($payload, (int) $request->user()->id);

        return ApiResponse::success(new QuickStockOutResource($qso), 'Quick stock out posted successfully.', 201);
    }

    public function convert(ConvertQuickStockOutRequest $request, QuickStockOut $quickStockOut): JsonResponse
    {
        $this->authorize('update', $quickStockOut);

        $saleOrder = $this->convertQuickStockOut->execute(
            $quickStockOut,
            $request->validated(),
            (int) $request->user()->id,
        );

        return ApiResponse::success([
            'sale_order_id' => (int) $saleOrder->id,
            'so_number' => $saleOrder->so_number,
            'status' => $saleOrder->status?->value,
        ], 'Quick stock out converted to sales order successfully.');
    }
}
