<?php

declare(strict_types=1);

use App\Http\Controllers\Ops\OpsController;
use App\Http\Controllers\Panel\PanelController;
use Illuminate\Support\Facades\Route;

Route::get('/painel/entrar', [PanelController::class, 'loginForm'])->name('panel.login');
Route::post('/painel/entrar', [PanelController::class, 'login'])->name('panel.login.submit');
Route::get('/painel/entrar/codigo', [PanelController::class, 'smsForm'])->name('panel.sms');
Route::post('/painel/entrar/codigo', [PanelController::class, 'verifySms'])->name('panel.sms.verify');
Route::post('/painel/entrar/google', [PanelController::class, 'google'])->name('panel.google');
Route::post('/painel/sair', [PanelController::class, 'logout'])->name('panel.logout');

Route::middleware('panel.admin')->prefix('painel')->name('panel.')->group(static function (): void {
    Route::get('/', [PanelController::class, 'dashboard'])->name('dashboard');
    Route::get('/clientes', [PanelController::class, 'clients'])->name('clients');
    Route::post('/clientes', [PanelController::class, 'storeClient'])->name('clients.store');
    Route::get('/clientes/{investorId}', [PanelController::class, 'client'])->name('client');
    Route::post('/clientes/{investorId}/opt-in', [PanelController::class, 'optIn'])->name('client.optin');
    Route::post('/clientes/{investorId}/ativar', [PanelController::class, 'activate'])->name('client.activate');
    Route::post('/pagamentos/{paymentId}/baixa', [PanelController::class, 'manualSettlement'])->name('payment.settle');
    Route::get('/revisoes', [PanelController::class, 'reviews'])->name('reviews');
    Route::post('/revisoes/{caseId}/resolver', [PanelController::class, 'resolveReview'])->name('reviews.resolve');
    Route::get('/cobranca', [PanelController::class, 'billing'])->name('billing');
    Route::get('/metricas', [PanelController::class, 'metrics'])->name('metrics');
    Route::get('/clientes/{investorId}/dados', [PanelController::class, 'dataReview'])->name('client.data');
    Route::post('/clientes/{investorId}/dados/exportar', [PanelController::class, 'exportData'])->name('client.data.export');
    Route::post('/clientes/{investorId}/dados/excluir', [PanelController::class, 'deleteData'])->name('client.data.delete');
    Route::post('/dados/{requestId}/executar', [PanelController::class, 'executeDataRequest'])->name('data.execute');
});

Route::prefix('ops')->middleware('ops.token')->group(static function (): void {
    Route::get('/health', [OpsController::class, 'health'])->name('ops.health');
    Route::get('/metrics', [OpsController::class, 'metrics'])->name('ops.metrics');
    Route::post('/alerts/test', [OpsController::class, 'testAlert'])->name('ops.alert.test');
});
