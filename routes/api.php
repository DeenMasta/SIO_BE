<?php

use App\Application\Support\ApiResponse;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MasterData\CustomerController;
use App\Http\Controllers\Api\MasterData\ProductController;
use App\Http\Controllers\Api\MasterData\SupplierController;
use App\Http\Controllers\Api\PurchasingInbound\PurchaseOrderController;
use App\Http\Controllers\Api\PurchasingInbound\StockInController;
use App\Http\Controllers\Api\ExceptionsReturns\CustomerReturnController;
use App\Http\Controllers\Api\ExceptionsReturns\RepairController;
use App\Http\Controllers\Api\ExceptionsReturns\ReturnToSupplierController;
use App\Http\Controllers\Api\ReportingAudit\AuditLogController;
use App\Http\Controllers\Api\ReportingAudit\DashboardController;
use App\Http\Controllers\Api\ReportingAudit\MovementReportController;
use App\Http\Controllers\Api\QcOutbound\QcTransactionController;
use App\Http\Controllers\Api\QcOutbound\StockOutController;
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
    Route::apiResource('qc-transactions', QcTransactionController::class)->only(['index', 'store', 'show']);
    Route::apiResource('stock-outs', StockOutController::class)->only(['index', 'store', 'show']);
    Route::apiResource('repairs', RepairController::class)->only(['index', 'store', 'show']);
    Route::patch('repairs/{id}/status', [RepairController::class, 'updateStatus']);
    Route::apiResource('return-to-suppliers', ReturnToSupplierController::class)->only(['index', 'store', 'show']);
    Route::apiResource('customer-returns', CustomerReturnController::class)->only(['index', 'store', 'show']);

    Route::get('dashboard/summary', [DashboardController::class, 'index'])->middleware('can:access-staff');
    Route::get('reports/stock-movements', [MovementReportController::class, 'index'])->middleware('can:access-staff');
    Route::get('reports/stock-movements/export', [MovementReportController::class, 'export'])->middleware('can:access-staff');
    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('can:access-admin');
    Route::get('audit-logs/export', [AuditLogController::class, 'export'])->middleware('can:access-admin');
});
