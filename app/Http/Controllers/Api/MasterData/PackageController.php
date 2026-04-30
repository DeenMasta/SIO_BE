<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MasterData\Package\StorePackageRequest;
use App\Http\Requests\Api\MasterData\Package\UpdatePackageRequest;
use App\Http\Resources\Api\MasterData\PackageResource;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PackageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Package::class);

        $perPage = (int) $request->integer('per_page', 15);
        
        $packages = Package::with('products')->latest()->paginate($perPage);

        return ApiResponse::success(
            PackageResource::collection($packages->items()),
            'Packages retrieved successfully.',
            meta: [
                'pagination' => [
                    'current_page' => $packages->currentPage(),
                    'per_page' => $packages->perPage(),
                    'total' => $packages->total(),
                    'last_page' => $packages->lastPage(),
                ],
            ],
        );
    }

    public function store(StorePackageRequest $request): JsonResponse
    {
        $this->authorize('create', Package::class);

        $payload = $request->validated();
        $payload['created_by'] = (int) $request->user()->id;
        $products = $payload['products'] ?? [];
        unset($payload['products']);

        $package = DB::transaction(function () use ($payload, $products) {
            $pkg = Package::create($payload);
            
            $syncData = [];
            foreach ($products as $item) {
                $syncData[$item['product_id']] = ['quantity' => $item['quantity']];
            }
            $pkg->products()->sync($syncData);
            
            return $pkg->load('products');
        });

        return ApiResponse::success(new PackageResource($package), 'Package created successfully.', 201);
    }

    public function show(Package $package): JsonResponse
    {
        $this->authorize('view', $package);

        return ApiResponse::success(new PackageResource($package->loadMissing('products')), 'Package retrieved successfully.');
    }

    public function update(UpdatePackageRequest $request, Package $package): JsonResponse
    {
        $this->authorize('update', $package);

        $payload = $request->validated();
        $products = $payload['products'] ?? [];
        unset($payload['products']);

        $updatedPackage = DB::transaction(function () use ($package, $payload, $products) {
            $package->update($payload);
            
            $syncData = [];
            foreach ($products as $item) {
                $syncData[$item['product_id']] = ['quantity' => $item['quantity']];
            }
            $package->products()->sync($syncData);
            
            return $package->load('products');
        });

        return ApiResponse::success(new PackageResource($updatedPackage), 'Package updated successfully.');
    }

    public function destroy(Package $package): JsonResponse
    {
        $this->authorize('delete', $package);

        DB::transaction(function () use ($package) {
            $package->products()->detach();
            $package->delete();
        });

        return ApiResponse::success(null, 'Package deleted successfully.');
    }
}
