<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Conversation\GuardedLlmGateway;
use App\Domain\Conversation\HttpLlmGateway;
use App\Domain\Conversation\LlmBudget;
use App\Domain\Conversation\LlmCircuitBreaker;
use App\Domain\Conversation\LlmGateway;
use App\Domain\Conversation\LlmOutputValidator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LlmGateway::class, static function (Application $app): ?LlmGateway {
            if ((bool) config('llm.enabled', false) !== true || config('llm.driver', 'http') !== 'http') {
                return null;
            }

            return new GuardedLlmGateway(
                $app->make(HttpLlmGateway::class),
                $app->make(LlmCircuitBreaker::class),
                $app->make(LlmBudget::class),
                new LlmOutputValidator((array) config('llm.output', [])),
            );
        });
    }
}
