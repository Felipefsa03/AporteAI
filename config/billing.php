<?php

declare(strict_types=1);

return [
    'product' => [
        'code' => 'aporteai-mvp',
        'name' => 'AporteAI MVP',
    ],
    'currency' => 'BRL',

    /*
     * RB-008/RB-033: valores em centavos; sem prazo de tolerancia presumido no MVP.
     * A tolerancia so existe se o contrato/registro da assinatura definir grace_days > 0.
     */
    'grace_days' => env('BILLING_GRACE_DAYS', 0),

    'features' => [
        'whatsapp_bot',
        'suitability',
        'portfolio_report',
        'human_review',
    ],

    /*
     * RB-031/RB-033/FL-05: o gateway e a unica fonte de link e de quitação.
     * indisponibilidade não é dívida confirmada; links só são exibidos quando
     * vêm do provedor, usam HTTPS e pertencem a um host permitido.
     */
    'gateway' => [
        'available' => env('BILLING_GATEWAY_AVAILABLE', true),
        'allowed_hosts' => array_values(array_filter(explode(',', (string) env('BILLING_GATEWAY_HOSTS', '')))),
    ],

    'commands' => [
        'plan' => ['MEU PLANO', 'MINHA ASSINATURA'],
        'reconcile' => ['REGULARIZAR PAGAMENTO', 'JÁ PAGUEI', 'JA PAGUEI'],
        'handoff' => ['FALAR COM ATENDIMENTO', 'FALAR COM ATENDENTE', 'FALAR COM PESSOA', 'HUMANO'],
        'privacy' => ['MEUS DADOS', 'EXPORTAR DADOS', 'SOLICITAR EXCLUSÃO', 'SOLICITAR EXCLUSAO'],
        'cancellation' => ['CANCELAR ASSINATURA'],
    ],

    'plans' => [
        'monthly' => [
            'code' => 'monthly',
            'label' => 'R$ 29,90/mês',
            'installments' => 1,
            'amount_cents' => 2990,
            'total_cents' => 2990,
        ],
        'annual' => [
            'code' => 'annual',
            'label' => '12 × R$ 19,90',
            'installments' => 12,
            'amount_cents' => 1990,
            'total_cents' => 23880,
        ],
    ],
];
