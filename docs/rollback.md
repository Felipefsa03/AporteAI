# Plano de rollback do piloto

## Objetivo

Remover dados e configurações do piloto técnico sem apagar a trilha de auditoria necessária à investigação.

## Passos

1. Desativar o piloto:

```bash
# Definir PILOT_ENABLED=false no ambiente; nada em código autoriza recommendations públicas.
```

2. Remover dados do piloto (ordem inversa das dependências):

```bash
php artisan migrate:rollback --step=1 --force  # data_rights_requests
php artisan migrate:rollback --step=1 --force  # reports
php artisan migrate:rollback --step=1 --force  # review_cases
php artisan migrate:rollback --step=1 --force  # aporte_sessions
php artisan migrate:rollback --step=1 --force  # explanations
```

3. Para rollback total do MVP em ambiente isolado:

```bash
php artisan migrate:rollback --step=19 --force
```

> `audit_events` é preservado por padrão (`config/pilot.php`) paramanter evidência. Se a política de retenção exigir remoção, executar com autorização humana registrada.

4. Verificação pós-rollback:

```bash
php artisan migrate:status
php artisan test --testsuite=Feature
```

## Critérios de aceite do rollback

- Nenhum investidor com telefone iniciando `+5575992699` permanece.
- Nenhum `external_id` iniciado com `pilot-` permanece em `portfolio_events`.
- `config/pilot.php::public_recommendations` continua `false`.
- Trilha de auditoria preservada ou removida conforme decisão humana documentada.
