<?php

declare(strict_types=1);

return [
    /*
     * Rate limiting do login do proprietario nao depende de tabela de cache.
     * O ambiente de teste usa o store em memoria; producao pode definir CACHE_STORE.
     */
    'default' => env('CACHE_STORE', 'array'),

    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ],
    ],
];
