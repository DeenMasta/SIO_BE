<?php

namespace App\Http\Controllers\Api\ExceptionsReturns;

use App\Application\Contracts\Repositories\CustomerReturnRepository;
use App\Application\ExceptionsReturns\CustomerReturns\UseCases\CreateCustomerReturnUseCase;
use App\Application\ExceptionsReturns\CustomerReturns\UseCases\ListCustomerReturnsUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExceptionsReturns\CustomerReturn\StoreCustomerReturnRequest;
use App\Http\Resources\Api\ExceptionsReturns\CustomerReturnResource;
use App\Models\CustomerReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerReturnController extends Controller
{
    public function __construct(
        private readonly ListCustomerReturnsUseCase $listReturns,
        private readonly CreateCustomerReturnUseCase $createReturn,
        private readonly CustomerReturnRepository $returns,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerReturn::class);

        $records = $this->listReturns->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            CustomerReturnResource::collection($records->items()),
            'Customer returns retrieved successfully.',
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

    public function store(StoreCustomerReturnRequest $request): JsonResponse
    {
        $this->authorize('create', CustomerReturn::class);

        $payload = $request->validated();
        $payload['created_by'] = (int) $request->user()->id;

        $record = $this->createReturn->execute($payload);

        return ApiResponse::success(new CustomerReturnResource($record), 'Customer return posted successfully.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $record = $this->returns->findOrFail($id);
        $this->authorize('view', $record);

        return ApiResponse::success(new CustomerReturnResource($record), 'Customer return retrieved successfully.');
    }
}
