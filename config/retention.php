<?php

declare(strict_types=1);

return [
    /*
     * A9: exclusão não é imediata; obrigações contratuais/fiscais definem a retenção.
     */
    'deletion_grace_days' => 90,
    'exportable' => [
        'full_name',
        'phone_e164',
        'email',
        'profession',
        'income_monthly_brl',
        'lifecycle_status',
    ],
    'correctable' => [
        'email',
        'profession',
        'income_monthly_brl',
    ],
];
