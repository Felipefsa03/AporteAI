<?php

declare(strict_types=1);

return [
    /*
     * A13/RB-010: o LLM recebe somente a proposta validada pelo motor.
     * Nenhum texto livre do cliente entra no prompt de recomendação.
     */
    'model' => env('LLM_MODEL', 'unconfigured'),
    'prompt_version' => 'explanation-2026.11-v1',
];
