<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ActivationController;
use App\Http\Controllers\Admin\AllocationController;
use App\Http\Controllers\Admin\AporteController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\InvestorController;
use App\Http\Controllers\Admin\OnboardingController;
use App\Http\Controllers\Admin\PortfolioController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReviewController;
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

    Route::post('/admin/clients/{investorId}/onboarding/start', [OnboardingController::class, 'start'])
        ->whereUuid('investorId');
    Route::post('/admin/onboarding/{sessionId}/answer', [OnboardingController::class, 'answer'])
        ->whereUuid('sessionId');

    Route::get('/admin/clients/{investorId}/portfolio', [PortfolioController::class, 'show'])
        ->whereUuid('investorId');
    Route::post('/admin/clients/{investorId}/portfolio/recommendations', [PortfolioController::class, 'recommend'])
        ->whereUuid('investorId');
    Route::post('/admin/clients/{investorId}/portfolio/executions', [PortfolioController::class, 'execute'])
        ->whereUuid('investorId');
    Route::post('/admin/clients/{investorId}/allocation', [AllocationController::class, 'propose'])
        ->whereUuid('investorId');

    Route::post('/admin/clients/{investorId}/aporte', [AporteController::class, 'start'])
        ->whereUuid('investorId');
    Route::post('/admin/aporte/{sessionId}/answer', [AporteController::class, 'answer'])
        ->whereUuid('sessionId');
    Route::post('/admin/aporte/{sessionId}/propose', [AporteController::class, 'propose'])
        ->whereUuid('sessionId');
    Route::post('/admin/aporte/{sessionId}/confirm', [AporteController::class, 'confirm'])
        ->whereUuid('sessionId');

    Route::get('/admin/reviews', [ReviewController::class, 'index']);
    Route::post('/admin/reviews/{caseId}/resolve', [ReviewController::class, 'resolve'])
        ->whereUuid('caseId');

    Route::post('/admin/clients/{investorId}/reports', [ReportController::class, 'store'])
        ->whereUuid('investorId');
});
