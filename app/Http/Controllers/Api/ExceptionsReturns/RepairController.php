<?php

namespace App\Http\Controllers\Api\ExceptionsReturns;

use App\Application\Contracts\Repositories\RepairRepository;
use App\Application\ExceptionsReturns\Repairs\UseCases\CreateRepairUseCase;
use App\Application\ExceptionsReturns\Repairs\UseCases\ListRepairsUseCase;
use App\Application\ExceptionsReturns\Repairs\UseCases\UpdateRepairStatusUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExceptionsReturns\Repair\StoreRepairRequest;
use App\Http\Requests\Api\ExceptionsReturns\Repair\UpdateRepairStatusRequest;
use App\Http\Resources\Api\ExceptionsReturns\RepairResource;
use App\Models\Repair;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RepairController extends Controller
{
    public function __construct(
        private readonly ListRepairsUseCase $listRepairs,
        private readonly CreateRepairUseCase $createRepair,
        private readonly UpdateRepairStatusUseCase $updateRepairStatus,
        private readonly RepairRepository $repairs,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Repair::class);

        $records = $this->listRepairs->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            RepairResource::collection($records->items()),
            'Repairs retrieved successfully.',
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

    public function store(StoreRepairRequest $request): JsonResponse
    {
        $this->authorize('create', Repair::class);

        $payload = $request->validated();
        $payload['created_by'] = (int) $request->user()->id;

        $repair = $this->createRepair->execute($payload);

        return ApiResponse::success(new RepairResource($repair), 'Repair created successfully.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $repair = $this->repairs->findOrFail($id);
        $this->authorize('view', $repair);

        return ApiResponse::success(new RepairResource($repair), 'Repair retrieved successfully.');
    }

    public function updateStatus(UpdateRepairStatusRequest $request, int $id): JsonResponse
    {
        $repair = $this->repairs->findOrFail($id);
        $this->authorize('update', $repair);

        $updated = $this->updateRepairStatus->execute([
            'repair' => $repair,
            ...$request->validated(),
            'updated_by' => (int) $request->user()->id,
        ]);

        return ApiResponse::success(new RepairResource($updated), 'Repair status updated successfully.');
    }
}
