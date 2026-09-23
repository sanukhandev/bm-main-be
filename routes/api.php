<?php

use App\Http\Controllers\Api\V1\AccountsController;
use App\Http\Controllers\Api\V1\AdministrationController;
use App\Http\Controllers\Api\V1\AgreementInstallmentController;
use App\Http\Controllers\Api\V1\AgreementOperationsController;
use App\Http\Controllers\Api\V1\AgreementPaymentController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\MaintenanceController;
use App\Http\Controllers\Api\V1\OwnerAgreementController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\ReportsController;
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
        Route::get('/dashboard/operational', [DashboardController::class, 'operational']);
        Route::get('/reports/owner-agreements', [ReportsController::class, 'ownerAgreements']);
        Route::get('/reports/tenant-agreements', [ReportsController::class, 'tenantAgreements']);
        Route::get('/reports/agreement-expiry', [ReportsController::class, 'expiry']);
        Route::get('/reports/tenant-outstanding', [ReportsController::class, 'tenantOutstanding'])->middleware('permission:accounts.view');
        Route::get('/reports/owner-payables', [ReportsController::class, 'ownerPayables'])->middleware('permission:accounts.view');
        Route::get('/reports/inward-receipts', [ReportsController::class, 'inwardReceipts'])->middleware('permission:accounts.view');
        Route::get('/reports/outward-vouchers', [ReportsController::class, 'outwardVouchers'])->middleware('permission:accounts.view');
        Route::get('/reports/daily-cash-movement', [ReportsController::class, 'dailyCashMovement'])->middleware('permission:accounts.view');
        Route::get('/reports/petty-cash', [ReportsController::class, 'pettyCash'])->middleware('permission:accounts.view');
        Route::get('/accounts/dashboard', [AccountsController::class, 'dashboard'])->middleware('permission:accounts.view');
        Route::get('/accounts/inward', [AccountsController::class, 'inward'])->middleware('permission:accounts.view');
        Route::get('/accounts/outward', [AccountsController::class, 'outward'])->middleware('permission:accounts.view');
        Route::get('/accounts/petty-cash/daybook', [AccountsController::class, 'pettyDaybook'])->middleware('permission:accounts.view');
        Route::get('/accounts/reports/daily-movement', [AccountsController::class, 'dailyMovement'])->middleware('permission:accounts.view');
        Route::get('/accounts/reports/payment-modes', [AccountsController::class, 'paymentModes'])->middleware('permission:accounts.view');
        Route::post('/accounts/petty-cash', [AccountsController::class, 'pettyCash'])->middleware('permission:accounts.post');
        Route::post('/accounts/transactions/{transaction}/void', [AccountsController::class, 'void'])->middleware('permission:accounts.void');
        Route::post('/accounts/transactions/{transaction}/cheque/{action}', [AccountsController::class, 'chequeAction'])->where('action', 'deposit|clear|bounce|cancel')->middleware('permission:accounts.post');
        Route::get('/quotations', [BillingController::class, 'quotations']);
        Route::post('/quotations', [BillingController::class, 'storeQuotation']);
        Route::get('/quotations/{quotation}', [BillingController::class, 'quotation']);
        Route::patch('/quotations/{quotation}', [BillingController::class, 'updateQuotation']);
        Route::delete('/quotations/{quotation}', [BillingController::class, 'deleteQuotation']);
        Route::post('/quotations/{quotation}/convert-to-invoice', [BillingController::class, 'convertQuotation']);
        Route::post('/quotations/{quotation}/payments', [BillingController::class, 'addQuotationPayment'])->middleware('permission:accounts.post');
        Route::patch('/quotations/{quotation}/payments/{payment}/status', [BillingController::class, 'quotationPaymentStatus'])->middleware('permission:accounts.post');
        Route::get('/invoices', [BillingController::class, 'invoices']);
        Route::post('/invoices', [BillingController::class, 'storeInvoice']);
        Route::get('/invoices/{invoice}', [BillingController::class, 'invoice']);
        Route::patch('/invoices/{invoice}', [BillingController::class, 'updateInvoice']);
        Route::delete('/invoices/{invoice}', [BillingController::class, 'deleteInvoice']);
        Route::post('/invoices/{invoice}/payments', [BillingController::class, 'addInvoicePayment'])->middleware('permission:accounts.post');
        Route::patch('/invoices/{invoice}/payments/{payment}/status', [BillingController::class, 'invoicePaymentStatus'])->middleware('permission:accounts.post');
        Route::post('/tenant-agreements/{tenantAgreement}/payments', [AgreementPaymentController::class, 'tenant'])->middleware('permission:accounts.post');
        Route::post('/owner-agreements/{ownerAgreement}/payments', [AgreementPaymentController::class, 'owner'])->middleware('permission:accounts.post');
        Route::patch('/owner-agreements/{agreement}/installments/{installment}/status', [AgreementInstallmentController::class, 'updateOwner']);
        Route::patch('/tenant-agreements/{agreement}/installments/{installment}/status', [AgreementInstallmentController::class, 'updateTenant']);
        Route::patch('/{type}-agreements/{agreement}/status', [AgreementOperationsController::class, 'transition'])->where('type', 'owner|tenant');
        Route::post('/{type}-agreements/{agreement}/{action}', [AgreementOperationsController::class, 'lifecycle'])
            ->where(['type' => 'owner|tenant', 'action' => 'submit|approve|commence|hold|resume|expire|terminate|cancel|extend|renew']);
        Route::post('/{type}-agreements/{agreement}/disputes', [AgreementOperationsController::class, 'disputes'])->where('type', 'owner|tenant');
        Route::post('/agreement-disputes/{dispute}/comments', [AgreementOperationsController::class, 'comment']);
        Route::post('/{type}-agreements/{agreement}/additional-payments', [AgreementOperationsController::class, 'additionalPayment'])->where('type', 'owner|tenant')->middleware('permission:accounts.post');
        Route::patch('/{type}-agreements/{agreement}/additional-payments/{payment}/status', [AgreementOperationsController::class, 'additionalPaymentStatus'])->where('type', 'owner|tenant')->middleware('permission:accounts.post');
        Route::get('/maintenance/vendors', [MaintenanceController::class, 'vendors']);
        Route::post('/maintenance/vendors', [MaintenanceController::class, 'storeVendor']);
        Route::patch('/maintenance/vendors/{vendor}', [MaintenanceController::class, 'updateVendor']);
        Route::delete('/maintenance/vendors/{vendor}', [MaintenanceController::class, 'deleteVendor']);
        Route::get('/maintenance/inventory', [MaintenanceController::class, 'inventory']);
        Route::post('/maintenance/inventory', [MaintenanceController::class, 'storeInventory']);
        Route::patch('/maintenance/inventory/{item}', [MaintenanceController::class, 'updateInventory']);
        Route::delete('/maintenance/inventory/{item}', [MaintenanceController::class, 'deleteInventory']);
        Route::get('/maintenance/work-orders', [MaintenanceController::class, 'workOrders']);
        Route::post('/maintenance/work-orders', [MaintenanceController::class, 'storeWorkOrder']);
        Route::get('/maintenance/work-orders/{workOrder}', [MaintenanceController::class, 'showWorkOrder']);
        Route::patch('/maintenance/work-orders/{workOrder}', [MaintenanceController::class, 'updateWorkOrder']);
        Route::post('/maintenance/work-orders/{workOrder}/payments', [MaintenanceController::class, 'storeWorkOrderPayment'])->middleware('permission:accounts.post');
        Route::patch('/maintenance/work-orders/{workOrder}/payments/{payment}/status', [MaintenanceController::class, 'updateWorkOrderPaymentStatus'])->middleware('permission:accounts.post');
        Route::patch('/maintenance/work-orders/{workOrder}/status', [MaintenanceController::class, 'status']);
        Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::get('/properties/available', [PropertyController::class, 'available']);
        Route::apiResource('properties', PropertyController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('owner-agreements', OwnerAgreementController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('tenant-agreements', TenantAgreementController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    });
});
