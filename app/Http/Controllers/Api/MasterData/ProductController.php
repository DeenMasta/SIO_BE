<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Application\MasterData\Products\UseCases\CreateProductUseCase;
use App\Application\MasterData\Products\UseCases\DeleteProductUseCase;
use App\Application\MasterData\Products\UseCases\ListProductsUseCase;
use App\Application\MasterData\Products\UseCases\UpdateProductUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MasterData\Product\StoreProductRequest;
use App\Http\Requests\Api\MasterData\Product\UpdateProductRequest;
use App\Http\Resources\Api\MasterData\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private readonly ListProductsUseCase $listProducts,
        private readonly CreateProductUseCase $createProduct,
        private readonly UpdateProductUseCase $updateProduct,
        private readonly DeleteProductUseCase $deleteProduct,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $products = $this->listProducts->execute([
            'per_page' => (int) $request->integer('per_page', 15),
        ]);

        return ApiResponse::success(
            ProductResource::collection($products->items()),
            'Products retrieved successfully.',
            meta: [
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'last_page' => $products->lastPage(),
                ],
            ],
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $payload = $request->validated();
        $payload['created_by'] = (int) $request->user()->id;

        $product = $this->createProduct->execute($payload);

        return ApiResponse::success(new ProductResource($product), 'Product created successfully.', 201);
    }

    public function show(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        return ApiResponse::success(new ProductResource($product->loadMissing('supplier', 'accessories', 'conditions')), 'Product retrieved successfully.');
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $updated = $this->updateProduct->execute([
            'product' => $product,
            ...$request->validated(),
        ]);

        return ApiResponse::success(new ProductResource($updated), 'Product updated successfully.');
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $this->deleteProduct->execute($product);

        return ApiResponse::success(null, 'Product deleted successfully.');
    }
}
