<?php

declare(strict_types=1);

use App\Console\Commands\SyncMarketDataCommand;
use App\Http\Middleware\EnsureAdminMfa;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        health: '/up',
    )
    ->withCommands([
        SyncMarketDataCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.mfa' => EnsureAdminMfa::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A API deve devolver erros sem expor PII; a camada de resposta do Laravel faz isso por padrao.
    })
    ->create();
