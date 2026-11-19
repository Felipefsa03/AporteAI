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

    /*
     * A9/A14/RB-014: política executável. Desligada por padrão: nenhuma C05 foi aprovada
     * por executor, então a API só pode simular até revisão humana autorizada.
     */
    'policy' => [
        'version' => 'retention-policy-v1',
        'approved' => false,
        'categories' => [
            'identity' => [
                'action' => 'anonymize',
                'basis' => 'LGPD art. 18 — titular não mantém identificadores após exclusão aprovada',
            ],
            'suitability' => [
                'action' => 'anonymize',
                'basis' => 'Perfil de investidor é dado pessoal sensível para fins de exclusão',
            ],
            'consent' => [
                'action' => 'retain',
                'basis' => 'Evidência de consentimento e revogação deve permanecer auditável',
            ],
            'contract' => [
                'action' => 'retain',
                'basis' => 'Obrigação contratual e civil preserva o vínculo formal',
            ],
            'portfolio' => [
                'action' => 'retain',
                'basis' => 'Registro de operações exigido por obrigação regulatória',
            ],
            'billing' => [
                'action' => 'retain',
                'basis' => 'Obrigação fiscal e financeira preserva histórico de cobrança',
            ],
            'audit' => [
                'action' => 'retain',
                'basis' => 'Trilha de auditoria é imutável e não pode ser apagada',
            ],
        ],
    ],

    'legal_hold' => [
        'active' => true,
        'categories' => ['contract', 'portfolio', 'billing', 'audit'],
    ],

    'require_human_review' => true,
    'approved_outcomes' => ['deletion_approved'],
    'dry_run' => true,

    'correction_rules' => [
        'email' => ['required', 'email', 'max:255'],
        'profession' => ['required', 'string', 'max:120'],
        'income_monthly_brl' => ['required', 'numeric', 'min:0'],
    ],
];
