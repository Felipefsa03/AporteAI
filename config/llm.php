<?php

declare(strict_types=1);

return [
    /*
     * A13/RB-010: o LLM recebe somente a proposta validada pelo motor.
     * Nenhum texto livre do cliente entra no prompt de recomendação.
     * O provider real permanece desligado até decisão humana e credenciais
     * configuradas fora do repositório.
     */
    'enabled' => env('LLM_ENABLED', false),
    'driver' => env('LLM_DRIVER', 'http'),
    'base_url' => env('LLM_BASE_URL', 'https://api.openai.com/v1'),
    'api_key' => env('LLM_API_KEY'),
    'model' => env('LLM_MODEL', 'unconfigured'),
    'prompt_version' => 'explanation-2026.11-v1',
    'timeout_seconds' => (int) env('LLM_TIMEOUT_SECONDS', 8),
    'max_tokens' => (int) env('LLM_MAX_TOKENS', 400),
    'temperature' => 0.0,

    'limits' => [
        'max_calls_per_hour' => (int) env('LLM_MAX_CALLS_PER_HOUR', 20),
        'max_tokens_per_hour' => (int) env('LLM_MAX_TOKENS_PER_HOUR', 20000),
        'max_cost_cents_per_day' => (int) env('LLM_MAX_COST_CENTS_PER_DAY', 500),
        'cost_per_1k_tokens_cents' => (int) env('LLM_COST_PER_1K_TOKENS_CENTS', 2),
    ],

    'circuit_breaker' => [
        'failure_threshold' => (int) env('LLM_FAILURE_THRESHOLD', 3),
        'cooldown_seconds' => (int) env('LLM_CIRCUIT_COOLDOWN_SECONDS', 300),
    ],

    'output' => [
        'min_chars' => 20,
        'max_chars' => 1200,
    ],

    'free_text_reply' => 'Assinatura ativa. Nenhuma recomendação é gerada por texto livre; solicite a explicação da proposta validada pelo fluxo dedicado.',
];
