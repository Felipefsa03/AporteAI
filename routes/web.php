<?php

declare(strict_types=1);

use App\Http\Controllers\Ops\OpsController;
use Illuminate\Support\Facades\Route;

Route::prefix('ops')->middleware('ops.token')->group(static function (): void {
    Route::get('/health', [OpsController::class, 'health'])->name('ops.health');
    Route::get('/metrics', [OpsController::class, 'metrics'])->name('ops.metrics');
    Route::post('/alerts/test', [OpsController::class, 'testAlert'])->name('ops.alert.test');
});
