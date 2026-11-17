<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\InvestorController;
use Illuminate\Support\Facades\Route;

Route::post('/admin/auth/login', [AuthController::class, 'login']);

Route::middleware('admin.mfa')->group(function (): void {
    Route::post('/admin/auth/logout', [AuthController::class, 'logout']);

    Route::get('/admin/investors', [InvestorController::class, 'index']);
    Route::post('/admin/investors', [InvestorController::class, 'store']);
    Route::patch('/admin/investors/{investorId}', [InvestorController::class, 'update'])
        ->whereUuid('investorId');

    Route::get('/admin/billing/plans', [BillingController::class, 'plans']);
    Route::get('/admin/billing/overdue', [BillingController::class, 'overdue']);
    Route::post('/admin/subscriptions/{subscriptionId}/reconcile', [BillingController::class, 'reconcile'])
        ->whereUuid('subscriptionId');
    Route::post('/admin/subscriptions/{subscriptionId}/suspend', [BillingController::class, 'suspend'])
        ->whereUuid('subscriptionId');
    Route::post('/admin/subscriptions/{subscriptionId}/reactivate', [BillingController::class, 'reactivate'])
        ->whereUuid('subscriptionId');
});
