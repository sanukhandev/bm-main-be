<?php

use App\Http\Controllers\Api\V1\AdministrationController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\OwnerAgreementController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\TenantAgreementController;
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

    Route::prefix('admin')->middleware(['auth:sanctum', 'user.active', 'throttle:api'])->group(function () {
        Route::get('/users', [AdministrationController::class, 'users']);
        Route::get('/roles', [AdministrationController::class, 'roles']);
    });

    Route::middleware(['auth:sanctum', 'user.active', 'branch.context', 'throttle:api'])->group(function () {
        Route::get('/dashboard/metrics', [DashboardController::class, 'metrics']);
        Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('properties', PropertyController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('owner-agreements', OwnerAgreementController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('tenant-agreements', TenantAgreementController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    });
});
