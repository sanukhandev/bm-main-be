<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->middleware('web')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

        Route::middleware(['auth:sanctum', 'user.active', 'throttle:api'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::get('/branches', [AuthController::class, 'branches']);
        });
    });

    Route::middleware(['auth:sanctum', 'user.active', 'branch.context', 'throttle:api'])->group(function () {
        Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show', 'update']);
    });
});
