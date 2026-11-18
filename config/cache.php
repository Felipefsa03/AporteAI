<?php

declare(strict_types=1);

return [
    /*
     * Cache geral pode seguir o ambiente; o contador de login do proprietario tem
     * store dedicado e persistente para sobreviver a processos HTTP distintos.
     */
    'default' => env('CACHE_STORE', 'array'),

    'limiter' => env('CACHE_LIMITER_STORE', 'limiter'),

    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
        'limiter' => [
            'driver' => 'database',
            'table' => 'cache',
            'lock_table' => 'cache_locks',
        ],
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ],
    ],
];
