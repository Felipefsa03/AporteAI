<?php

declare(strict_types=1);

return [
    /*
     * PIL: piloto técnico com dados fictícios. Nada aqui autoriza operação comercial.
     */
    'enabled' => env('PILOT_ENABLED', false),
    'public_recommendations' => false,
    'owner_dashboard_only' => true,
    'advisory_authorized' => false,
    'llm_model' => null,
    'fictitious_phone_prefix' => '+5575992699',
    'rollback' => [
        'delete_data_rights_requests' => true,
        'delete_reports' => true,
        'delete_aporte_sessions' => true,
        'delete_review_cases' => true,
        'keep_audit_events' => true,
    ],
];
