<?php

declare(strict_types=1);

return [
    /*
     * ADR-008 / RB-027: dois numeros distintos. O comercial e humano e nao
     * recebe automacao; somente o bot usa a Cloud API.
     */
    'bot' => [
        'phone_number_id' => env('WHATSAPP_BOT_PHONE_NUMBER_ID', '5575992605555'),
        'number_e164' => env('WHATSAPP_BOT_NUMBER_E164', '+5575992605555'),
    ],

    'commercial' => [
        'number_e164' => env('WHATSAPP_COMMERCIAL_NUMBER_E164', '+15416221559'),
    ],

    'cloud_api' => [
        'base_url' => env('WHATSAPP_CLOUD_API_BASE_URL', 'https://graph.facebook.com/v21.0'),
        'access_token' => env('WHATSAPP_CLOUD_API_TOKEN'),
    ],

    'webhook' => [
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    ],

    'templates' => [
        'activation' => env('WHATSAPP_ACTIVATION_TEMPLATE', 'aporteai_activation_v1'),
    ],

    'queue' => env('WHATSAPP_QUEUE', 'whatsapp'),

    'keywords' => [
        'opt_out' => ['STOP', 'SAIR', 'CANCELAR'],
        'human_handoff' => ['ATENDENTE', 'HUMANO', 'ATENDIMENTO'],
    ],
];
