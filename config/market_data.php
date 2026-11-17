<?php

declare(strict_types=1);

return [
    /*
     * Cada fonte declara licenca, direito de uso e politica de atualidade (A5/C07).
     * Nenhuma fonte sem autorizacao explicita pode alimentar recomendacoes.
     */
    'sources' => [
        'bcb_selic' => [
            'provider' => 'bcb',
            'dataset' => 'selic',
            'adapter' => 'bcb',
            'symbol' => 'SELIC',
            'date_field' => 'data',
            'price_field' => 'valor',
            'date_format' => 'Y-m-d',
            'license' => 'public',
            'usage_allowed' => true,
            'redistribution_allowed' => true,
            'max_age_seconds' => 86_400,
            'endpoint' => env('BCB_SELIC_URL', 'https://api.bcb.gov.br/dados/serie/bcdata.sgs.432/dados/ultimos/1?formato=json'),
        ],
        'bcb_focus' => [
            'provider' => 'bcb',
            'dataset' => 'focus',
            'adapter' => 'bcb',
            'symbol' => 'FOCUS_SELIC',
            'date_field' => 'data',
            'price_field' => 'valor',
            'date_format' => 'Y-m-d',
            'license' => 'public',
            'usage_allowed' => true,
            'redistribution_allowed' => true,
            'max_age_seconds' => 604_800,
            'endpoint' => env('BCB_FOCUS_URL', 'https://api.bcb.gov.br/dados/serie/bcdata.sgs.10778/dados/ultimos/1?formato=json'),
        ],
        'tesouro_prices' => [
            'provider' => 'tesouro',
            'dataset' => 'prices',
            'adapter' => 'generic',
            'symbol_field' => 'symbol',
            'date_field' => 'date',
            'price_field' => 'price',
            'date_format' => 'Y-m-d',
            'license' => 'public',
            'usage_allowed' => true,
            'redistribution_allowed' => true,
            'max_age_seconds' => 86_400,
            'endpoint' => env('TESOURO_PRICES_URL', ''),
        ],
        'cvm_funds' => [
            'provider' => 'cvm',
            'dataset' => 'funds',
            'adapter' => 'generic',
            'symbol_field' => 'cnpj',
            'date_field' => 'date',
            'price_field' => 'quota',
            'date_format' => 'Y-m-d',
            'license' => 'public',
            'usage_allowed' => true,
            'redistribution_allowed' => true,
            'max_age_seconds' => 172_800,
            'endpoint' => env('CVM_FUNDS_URL', ''),
        ],
        'commercial_quotes' => [
            'provider' => 'commercial',
            'dataset' => 'quotes',
            'adapter' => 'generic',
            'symbol_field' => 'symbol',
            'date_field' => 'as_of',
            'price_field' => 'price',
            'date_format' => DATE_ATOM,
            'license' => 'licensed',
            'usage_allowed' => env('MARKET_LICENSED_USAGE_ALLOWED', false),
            'redistribution_allowed' => env('MARKET_REDISTRIBUTION_ALLOWED', false),
            'max_age_seconds' => 300,
            'endpoint' => env('MARKET_QUOTES_URL', ''),
        ],
    ],
];
