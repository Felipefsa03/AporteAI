<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

use Illuminate\Support\Facades\Cache;

/** Circuit breaker global em cache; falhas abrem o caminho por um período curto. */
final class LlmCircuitBreaker
{
    public function assertClosed(): void
    {
        if ((bool) Cache::get('llm:circuit:open', false)) {
            throw new LlmProviderException('Circuit breaker do provedor de IA aberto.', 0, 'llm_circuit_open');
        }
    }

    public function recordSuccess(): void
    {
        Cache::forget('llm:circuit:failures');
        Cache::forget('llm:circuit:open');
    }

    public function recordFailure(): void
    {
        Cache::add('llm:circuit:failures', 0, 3600);
        $failures = (int) Cache::increment('llm:circuit:failures');

        if ($failures >= max(1, (int) config('llm.circuit_breaker.failure_threshold', 3))) {
            Cache::put(
                'llm:circuit:open',
                true,
                max(1, (int) config('llm.circuit_breaker.cooldown_seconds', 300)),
            );
        }
    }
}
