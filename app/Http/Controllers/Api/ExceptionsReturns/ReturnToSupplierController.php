<?php

namespace App\Http\Controllers\Api\ExceptionsReturns;

use App\Application\Contracts\Repositories\ReturnToSupplierRepository;
use App\Application\ExceptionsReturns\ReturnToSuppliers\UseCases\CancelReturnToSupplierUseCase;
use App\Application\ExceptionsReturns\ReturnToSuppliers\UseCases\CreateReturnToSupplierUseCase;
use App\Application\ExceptionsReturns\ReturnToSuppliers\UseCases\ListReturnToSuppliersUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExceptionsReturns\CancelExceptionTransactionRequest;
use App\Http\Requests\Api\ExceptionsReturns\ReturnToSupplier\StoreReturnToSupplierRequest;
use App\Http\Resources\Api\ExceptionsReturns\ReturnToSupplierResource;
use App\Models\ReturnToSupplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReturnToSupplierController extends Controller
{
    public function __construct(
        private readonly ListReturnToSuppliersUseCase $listReturns,
        private readonly CreateReturnToSupplierUseCase $createReturn,
        private readonly CancelReturnToSupplierUseCase $cancelReturn,
        private readonly ReturnToSupplierRepository $returns,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ReturnToSupplier::class);

        $records = $this->listReturns->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            ReturnToSupplierResource::collection($records->items()),
            'Return to supplier records retrieved successfully.',
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

    public function store(StoreReturnToSupplierRequest $request): JsonResponse
    {
        $this->authorize('create', ReturnToSupplier::class);

        $payload = $request->validated();
        $payload['created_by'] = (int) $request->user()->id;

        $record = $this->createReturn->execute($payload);

        return ApiResponse::success(new ReturnToSupplierResource($record), 'Return to supplier posted successfully.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $record = $this->returns->findOrFail($id);
        $this->authorize('view', $record);

        return ApiResponse::success(new ReturnToSupplierResource($record), 'Return to supplier retrieved successfully.');
    }

    public function cancel(CancelExceptionTransactionRequest $request, int $id): JsonResponse
    {
        $record = $this->returns->findOrFail($id);
        $this->authorize('create', ReturnToSupplier::class);

        $cancelled = $this->cancelReturn->execute([
            'return_to_supplier_id' => $id,
            'remarks' => $request->validated('remarks'),
            'cancelled_by' => (int) $request->user()->id,
        ]);

        return ApiResponse::success(new ReturnToSupplierResource($cancelled), 'Return to supplier cancelled successfully.');
    }
}
