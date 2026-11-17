<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ActivationController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\InvestorController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/webhooks/whatsapp-bot/verify', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp-bot', [WhatsAppWebhookController::class, 'webhook']);
Route::post('/webhooks/payment-gateway', [PaymentWebhookController::class, 'handle']);

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
    Route::post('/admin/payments/{paymentId}/manual-settlement', [BillingController::class, 'manualSettlement'])
        ->whereUuid('paymentId');
    Route::post('/admin/subscriptions/{subscriptionId}/suspend', [BillingController::class, 'suspend'])
        ->whereUuid('subscriptionId');
    Route::post('/admin/subscriptions/{subscriptionId}/reactivate', [BillingController::class, 'reactivate'])
        ->whereUuid('subscriptionId');

    Route::post('/admin/clients/{investorId}/opt-ins', [ActivationController::class, 'optIn'])
        ->whereUuid('investorId');
    Route::post('/admin/clients/{investorId}/activate', [ActivationController::class, 'activate'])
        ->whereUuid('investorId');
    Route::get('/admin/clients/{investorId}/activation', [ActivationController::class, 'status'])
        ->whereUuid('investorId');
    Route::get('/admin/handoff', [ActivationController::class, 'handoff']);
});
