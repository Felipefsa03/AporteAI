<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

use Illuminate\Support\Facades\Cache;

/** Limites globais e agregados: nenhuma chave contém telefone, nome ou prompt. */
final class LlmBudget
{
    public function assertAllowed(): void
    {
        $hour = now()->format('YmdH');
        $day = now()->format('Ymd');

        if ((int) Cache::get("llm:calls:$hour", 0) >= $this->maxCalls()) {
            throw new LlmLimitException('llm_call_limit');
        }

        if ((int) Cache::get("llm:tokens:$hour", 0) >= $this->maxTokens()) {
            throw new LlmLimitException('llm_token_limit');
        }

        if ((int) Cache::get("llm:cost:$day", 0) >= $this->maxCostCents()) {
            throw new LlmLimitException('llm_cost_limit');
        }
    }

    public function record(int $totalTokens): int
    {
        $totalTokens = max(0, $totalTokens);
        $hour = now()->format('YmdH');
        $day = now()->format('Ymd');
        $ttlHour = 3660;
        $ttlDay = 90000;

        Cache::add("llm:calls:$hour", 0, $ttlHour);
        Cache::add("llm:tokens:$hour", 0, $ttlHour);
        Cache::add("llm:cost:$day", 0, $ttlDay);
        Cache::increment("llm:calls:$hour");
        Cache::increment("llm:tokens:$hour", $totalTokens);

        $costCents = (int) ceil($totalTokens * $this->costPer1k() / 1000);
        if ($costCents > 0) {
            Cache::increment("llm:cost:$day", $costCents);
        }

        return $costCents;
    }

    private function maxCalls(): int
    {
        return max(1, (int) config('llm.limits.max_calls_per_hour', 20));
    }

    private function maxTokens(): int
    {
        return max(1, (int) config('llm.limits.max_tokens_per_hour', 20000));
    }

    private function maxCostCents(): int
    {
        return max(1, (int) config('llm.limits.max_cost_cents_per_day', 500));
    }

    private function costPer1k(): int
    {
        return max(0, (int) config('llm.limits.cost_per_1k_tokens_cents', 2));
    }
}
