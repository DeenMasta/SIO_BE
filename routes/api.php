<?php

use App\Application\Support\ApiResponse;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IdentityAccess\UserManagementController;
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
use App\Http\Controllers\Api\ReportingAudit\ReportPackController;
use App\Http\Controllers\Api\ReportingAudit\SearchController;
use App\Http\Controllers\Api\QcOutbound\QcTransactionController;
use App\Http\Controllers\Api\QcOutbound\StockOutController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/admin/ping', fn () => ApiResponse::success(['alive' => true], 'Admin access granted.'))
        ->middleware('can:access-admin');

    Route::middleware('can:access-admin')->group(function () {
        Route::get('/users', [UserManagementController::class, 'index']);
        Route::post('/users', [UserManagementController::class, 'store']);
        Route::patch('/users/{user}', [UserManagementController::class, 'update']);
        Route::patch('/users/{user}/activate', [UserManagementController::class, 'activate']);
        Route::patch('/users/{user}/deactivate', [UserManagementController::class, 'deactivate']);
    });

    Route::apiResource('products', ProductController::class);
    Route::apiResource('suppliers', SupplierController::class);
    Route::apiResource('customers', CustomerController::class);

    Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['index', 'store', 'show']);
    Route::patch('purchase-orders/{purchaseOrder}/issue', [PurchaseOrderController::class, 'issue']);
    Route::patch('purchase-orders/{purchaseOrder}/complete', [PurchaseOrderController::class, 'complete']);
    Route::patch('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
    Route::apiResource('stock-ins', StockInController::class)->only(['index', 'store', 'show']);
    Route::apiResource('qc-transactions', QcTransactionController::class)->only(['index', 'store', 'show']);
    Route::apiResource('stock-outs', StockOutController::class)->only(['index', 'store', 'show']);
    Route::apiResource('repairs', RepairController::class)->only(['index', 'store', 'show']);
    Route::patch('repairs/{id}/status', [RepairController::class, 'updateStatus']);
    Route::apiResource('return-to-suppliers', ReturnToSupplierController::class)->only(['index', 'store', 'show']);
    Route::patch('return-to-suppliers/{id}/cancel', [ReturnToSupplierController::class, 'cancel']);
    Route::apiResource('customer-returns', CustomerReturnController::class)->only(['index', 'store', 'show']);
    Route::patch('customer-returns/{id}/cancel', [CustomerReturnController::class, 'cancel']);

    Route::middleware('can:access-staff')->prefix('search')->group(function () {
        Route::get('products', [SearchController::class, 'products']);
        Route::get('serials', [SearchController::class, 'serials']);
        Route::get('invoices', [SearchController::class, 'invoices']);
        Route::get('purchase-orders', [SearchController::class, 'purchaseOrders']);
    });

    Route::get('dashboard/summary', [DashboardController::class, 'index'])->middleware('can:access-staff');
    Route::get('reports/stock-movements', [MovementReportController::class, 'index'])->middleware('can:access-staff');
    Route::get('reports/stock-movements/export', [MovementReportController::class, 'export'])->middleware('can:access-staff');

    Route::middleware('can:access-staff')->prefix('reports')->group(function () {
        Route::get('stock-balance', [ReportPackController::class, 'stockBalance']);
        Route::get('stock-card', [ReportPackController::class, 'stockCard']);
        Route::get('low-stock', [ReportPackController::class, 'lowStock']);
        Route::get('purchase-orders/summary', [ReportPackController::class, 'poSummary']);
        Route::get('purchase-orders/open', [ReportPackController::class, 'poOpen']);
        Route::get('purchase-orders/aging', [ReportPackController::class, 'poAging']);
        Route::get('stock-in/by-supplier-do', [ReportPackController::class, 'stockInBySupplierDo']);
        Route::get('qc/pass-fail', [ReportPackController::class, 'qcPassFail']);
        Route::get('stock-out/by-invoice-customer', [ReportPackController::class, 'stockOutByInvoiceCustomer']);
        Route::get('repairs/summary', [ReportPackController::class, 'repairSummary']);
        Route::get('rts/summary', [ReportPackController::class, 'rtsSummary']);
        Route::get('customer-returns/summary', [ReportPackController::class, 'customerReturnSummary']);
        Route::get('serial-trace', [ReportPackController::class, 'serialTrace']);
    });

    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('can:access-admin');
    Route::get('audit-logs/export', [AuditLogController::class, 'export'])->middleware('can:access-admin');
});
