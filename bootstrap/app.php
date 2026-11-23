<?php

declare(strict_types=1);

use App\Console\Commands\ApplyPortfolioEventCommand;
use App\Console\Commands\DispatchWhatsAppOutboxCommand;
use App\Console\Commands\ReplayDeadLettersCommand;
use App\Console\Commands\SyncMarketDataCommand;
use App\Http\Middleware\EnsureAdminMfa;
use App\Http\Middleware\EnsureOpsToken;
use App\Providers\BillingServiceProvider;
use App\Providers\LlmServiceProvider;
use App\Providers\WhatsAppServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        health: '/up',
    )
    ->withCommands([
        ApplyPortfolioEventCommand::class,
        DispatchWhatsAppOutboxCommand::class,
        ReplayDeadLettersCommand::class,
        SyncMarketDataCommand::class,
    ])
    ->withSchedule(function (Schedule $schedule): void {
        // C07/ADR003: sincronizacao automatica e opt-in, nunca habilitada por padrao.
        if ((bool) config('market_data.scheduler.enabled', false) !== true) {
            return;
        }

        $schedule->command('market:sync')
            ->cron((string) config('market_data.scheduler.cron', '0 6 * * *'))
            ->withoutOverlapping()
            ->onFailure(static function (): void {
                report(new \RuntimeException('market:sync falhou no agendamento.'));
            });
    })
    ->withProviders([
        BillingServiceProvider::class,
        LlmServiceProvider::class,
        WhatsAppServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.mfa' => EnsureAdminMfa::class,
            'ops.token' => EnsureOpsToken::class,
        ]);
        $middleware->validateCsrfTokens(except: ['ops/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A API deve devolver erros sem expor PII; a camada de resposta do Laravel faz isso por padrao.
    })
    ->create();
