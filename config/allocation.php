<?php

declare(strict_types=1);

return [
    /*
     * RB-005/RB-006/RB-007/RB-009: limites inteiros em bps; nenhum ponto flutuante.
     */
    'invested_bps_by_profile' => [
        'conservative' => 3000,
        'moderate' => 6000,
        'aggressive' => 8000,
    ],

    'max_risk_bps_by_profile' => [
        'conservative' => 2000,
        'moderate' => 5000,
        'aggressive' => 8000,
    ],

    'universe' => [
        'TESOURO-IPCA' => ['risk_bps' => 1000, 'source' => 'tesouro_prices'],
        'CDB-LIQUIDEZ' => ['risk_bps' => 1500, 'source' => 'bcb_selic'],
        'FUNCOES-REDITAS' => ['risk_bps' => 4500, 'source' => 'cvm_funds'],
    ],
];
