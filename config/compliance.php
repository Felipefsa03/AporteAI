<?php

declare(strict_types=1);

return [
    /*
     * A3/C04: permanece falso ate existir estrutura regulada, responsavel
     * tecnico e aprovacao formal. Nenhum instrumento pode ser nomeado antes disso.
     */
    'advisory_authorized' => env('ADVISORY_AUTHORIZED', false),

    'suitability_validity_days' => env('SUITABILITY_VALIDITY_DAYS', 180),
];
