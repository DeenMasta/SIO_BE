<?php

use App\Application\Support\ApiResponse;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/admin/ping', fn () => ApiResponse::success(['alive' => true], 'Admin access granted.'))
        ->middleware('can:access-admin');
});
