<?php

use App\Application\Support\ApiResponse;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MasterData\CustomerController;
use App\Http\Controllers\Api\MasterData\ProductController;
use App\Http\Controllers\Api\MasterData\SupplierController;
use App\Http\Controllers\Api\PurchasingInbound\PurchaseOrderController;
use App\Http\Controllers\Api\PurchasingInbound\StockInController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/admin/ping', fn () => ApiResponse::success(['alive' => true], 'Admin access granted.'))
        ->middleware('can:access-admin');

    Route::apiResource('products', ProductController::class);
    Route::apiResource('suppliers', SupplierController::class);
    Route::apiResource('customers', CustomerController::class);

    Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['index', 'store', 'show']);
    Route::apiResource('stock-ins', StockInController::class)->only(['index', 'store', 'show']);
});
