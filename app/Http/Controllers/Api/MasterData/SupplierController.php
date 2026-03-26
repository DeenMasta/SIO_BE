<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Application\MasterData\Suppliers\UseCases\CreateSupplierUseCase;
use App\Application\MasterData\Suppliers\UseCases\DeleteSupplierUseCase;
use App\Application\MasterData\Suppliers\UseCases\ListSuppliersUseCase;
use App\Application\MasterData\Suppliers\UseCases\UpdateSupplierUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MasterData\Supplier\StoreSupplierRequest;
use App\Http\Requests\Api\MasterData\Supplier\UpdateSupplierRequest;
use App\Http\Resources\Api\MasterData\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function __construct(
        private readonly ListSuppliersUseCase $listSuppliers,
        private readonly CreateSupplierUseCase $createSupplier,
        private readonly UpdateSupplierUseCase $updateSupplier,
        private readonly DeleteSupplierUseCase $deleteSupplier,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);

        $suppliers = $this->listSuppliers->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            SupplierResource::collection($suppliers->items()),
            'Suppliers retrieved successfully.',
            meta: [
                'pagination' => [
                    'current_page' => $suppliers->currentPage(),
                    'per_page' => $suppliers->perPage(),
                    'total' => $suppliers->total(),
                    'last_page' => $suppliers->lastPage(),
                ],
            ],
        );
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $this->authorize('create', Supplier::class);

        $supplier = $this->createSupplier->execute($request->validated());

        return ApiResponse::success(new SupplierResource($supplier), 'Supplier created successfully.', 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $this->authorize('view', $supplier);

        return ApiResponse::success(new SupplierResource($supplier), 'Supplier retrieved successfully.');
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $this->authorize('update', $supplier);

        $updated = $this->updateSupplier->execute([
            'supplier' => $supplier,
            ...$request->validated(),
        ]);

        return ApiResponse::success(new SupplierResource($updated), 'Supplier updated successfully.');
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->authorize('delete', $supplier);

        $this->deleteSupplier->execute($supplier);

        return ApiResponse::success(null, 'Supplier deleted successfully.');
    }
}
