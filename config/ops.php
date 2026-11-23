<?php

declare(strict_types=1);

return [
    /*
     * OPS: observabilidade local do piloto. Nenhum segredo é lido do repositório.
     */
    'token' => env('OPS_TOKEN'),

    'health' => [
        'provider_verification_stale_minutes' => (int) env('OPS_PROVIDER_STALE_MINUTES', 120),
    ],

    'thresholds' => [
        'failed_jobs' => (int) env('OPS_FAILED_JOBS_THRESHOLD', 20),
        'dead_letters' => (int) env('OPS_DEAD_LETTERS_THRESHOLD', 20),
    ],

    'targets' => [
        'rpo_minutes' => (int) env('OPS_RPO_MINUTES', 15),
        'rto_minutes' => (int) env('OPS_RTO_MINUTES', 60),
        'p95_ms' => (int) env('OPS_P95_MS', 800),
    ],
];
