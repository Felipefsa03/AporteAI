<?php

declare(strict_types=1);

return [
    /*
     * 'log' mantém o piloto sem custo; 'twilio' envia SMS de verdade quando as
     * credenciais abaixo estiverem presentes no ambiente (nunca no repositório).
     */
    'driver' => env('SMS_DRIVER', 'log'),
];
