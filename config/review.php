<?php

declare(strict_types=1);

return [
    /*
     * A8: apenas estes papéis podem resolver casos de revisão crítica.
     * Decisão humana nunca altera o bloqueio financeiro ou autoriza ordem.
     */
    'authorized_roles' => ['owner', 'compliance'],
    'blocked_outcomes' => ['release', 'execute_order', 'bypass_billing_gate'],
];
