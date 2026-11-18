<?php

declare(strict_types=1);

/*
 * Fontes publicas homologadas em 18/11/2026. Os contratos, esquemas e evidencias
 * de licenca estao documentados em docs/market-data-sources.md.
 *
 * C07/ADR003 permanece aberto: nenhuma fonte declara redistribuicao permitida
 * ate decisao humana. O provedor commercial fica desligado e sem adapter.
 */
return [
    'http' => [
        'timeout_seconds' => env('MARKET_HTTP_TIMEOUT_SECONDS', 30),
        'retries' => env('MARKET_HTTP_RETRIES', 2),
    ],

    'limits' => [
        'max_bytes' => env('MARKET_MAX_BYTES', 20_971_520),
        'max_uncompressed_bytes' => env('MARKET_MAX_UNCOMPRESSED_BYTES', 33_554_432),
        'max_entries' => env('MARKET_MAX_ENTRIES', 4),
        'max_records' => env('MARKET_MAX_RECORDS', 50_000),
        'max_ratio' => env('MARKET_MAX_RATIO', 100),
    ],

    'scheduler' => [
        // Integracao opt-in: sem habilitacao explicita nenhum job e agendado.
        'enabled' => env('MARKET_SCHEDULER_ENABLED', false),
        'cron' => env('MARKET_SCHEDULER_CRON', '0 6 * * *'),
    ],

    'sources' => [
        'bcb_selic' => [
            'provider' => 'bcb',
            'dataset' => 'sgs-432',
            'adapter' => 'bcb_sgs_json',
            'symbol' => 'SELIC',
            'date_field' => 'data',
            'price_field' => 'valor',
            'date_format' => 'd/m/Y',
            'timezone' => 'America/Sao_Paulo',
            'data_kind' => 'indicator',
            'unit' => 'percent',
            'license' => 'public',
            'usage_allowed' => true,
            'redistribution_allowed' => false,
            'license_evidence' => 'https://dadosabertos.bcb.gov.br/dataset/taxa-de-juros-selic',
            'max_age_seconds' => 86_400,
            'endpoint' => env('BCB_SELIC_URL', 'https://api.bcb.gov.br/dados/serie/bcdata.sgs.432/dados/ultimos/1?formato=json'),
        ],
        'bcb_focus' => [
            'provider' => 'bcb',
            'dataset' => 'expectativas-mercado-selic',
            'adapter' => 'bcb_focus_odata',
            'symbol' => 'FOCUS_SELIC',
            'date_field' => 'Data',
            'price_field' => 'Mediana',
            'date_format' => 'Y-m-d',
            'timezone' => 'America/Sao_Paulo',
            'data_kind' => 'indicator',
            'unit' => 'percent',
            'license' => 'public',
            'usage_allowed' => true,
            'redistribution_allowed' => false,
            'license_evidence' => 'https://dadosabertos.bcb.gov.br/dataset/expectativas-mercado',
            'max_age_seconds' => 604_800,
            'endpoint' => env('BCB_FOCUS_URL', 'https://olinda.bcb.gov.br/olinda/servico/Expectativas/versao/v1/odata/ExpectativasMercadoSelic?$top=50&$format=json'),
        ],
        'tesouro_prices' => [
            'provider' => 'tesouro',
            'dataset' => 'precos-e-taxas-titulos-ofertados',
            'adapter' => 'tesouro_csv',
            'symbol_field' => 'Tipo Titulo',
            'date_field' => 'Data Base',
            'price_field' => 'PU Venda Manha',
            'date_format' => 'd/m/Y',
            'timezone' => 'America/Sao_Paulo',
            'data_kind' => 'price',
            'unit' => 'brl',
            'license' => 'public',
            'usage_allowed' => true,
            'redistribution_allowed' => false,
            'license_evidence' => 'https://www.tesourotransparente.gov.br/ckan/dataset/taxas-dos-titulos-ofertados-pelo-tesouro-direto',
            'max_age_seconds' => 86_400,
            'endpoint' => env('TESOURO_PRICES_URL', 'https://www.tesourotransparente.gov.br/ckan/dataset/df56aa42-484a-4a59-8184-7676580c81e3/resource/796d2059-14e9-44e3-80c9-2d9e30b405c1/download/precotaxatesourodireto.csv'),
        ],
        'cvm_funds' => [
            'provider' => 'cvm',
            'dataset' => 'inf-diario-fi',
            'adapter' => 'cvm_inf_diario_zip',
            'symbol_field' => 'CNPJ_FUNDO_CLASSE',
            'date_field' => 'DT_COMPTC',
            'price_field' => 'VL_QUOTA',
            'date_format' => 'Y-m-d',
            'timezone' => 'America/Sao_Paulo',
            'data_kind' => 'price',
            'unit' => 'brl',
            'license' => 'public',
            'usage_allowed' => true,
            'redistribution_allowed' => false,
            'license_evidence' => 'https://dados.cvm.gov.br/dataset/fi-doc-inf_diario',
            'max_age_seconds' => 172_800,
            'endpoint' => env('CVM_INF_DIARIO_URL', 'https://dados.cvm.gov.br/dados/FI/DOC/INF_DIARIO/DADOS/inf_diario_fi_202611.zip'),
        ],
        'commercial_quotes' => [
            'provider' => 'commercial',
            'dataset' => 'quotes',
            'adapter' => null,
            'license' => 'licensed',
            // ADR003/C07 nao decidiram: sem adapter, sem uso e sem redistribuicao.
            'opt_in_required' => true,
            'usage_allowed' => false,
            'redistribution_allowed' => false,
            'license_evidence' => 'pending-ADR003-C07',
            'timezone' => 'UTC',
            'date_format' => DATE_ATOM,
            'data_kind' => 'price',
            'unit' => 'brl',
            'max_age_seconds' => 300,
            'endpoint' => env('MARKET_QUOTES_URL', ''),
        ],
    ],
];
