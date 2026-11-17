# Homologação P0 — AporteAI MVP v1.2

Status: **implementado e preparado para execução no CI**. O executor local não possui PHP, Composer, PostgreSQL ou Docker; portanto os testes foram escritos e validados estaticamente, mas a execução real depende do pipeline de CI e da revisão humana.

## Matriz de cenários (PRD §16)

| Cenário | Critério | Cobertura automatizada | Resultado esperado |
| --- | --- | --- | --- |
| A10 | Apenas a conta do proprietário acessa o dashboard; registros não se cruzam | `AdminDashboardTest`, `DashboardMetricsTest`, `P0HomologationTest::testA10OnlyOwnerAccessesDashboardAndRegistriesDoNotCross` | 401 sem sessão admin/MFA; métricas por `investor_id` |
| A13 | Inadimplente recebe apenas aviso de vencimento; zero tokens de LLM | `BillingGateTest::testA13OverdueMessageNeverCallsLlm`, `P0HomologationTest::testA13AndA14...` | `action=blocked`, `llm_calls=0`, link e data corretos |
| A14 | Comandos administrativos e direitos funcionam no bloqueio | `BillingGateTest::testA14AllowedCommandsWorkWithoutPaidServices`, `DataRightsTest::testA14...` | `action=command`, nenhuma liberação de serviço pago |
| A15 | Webhook `paid` válido restaura uma vez; duplicata e `processing` não | `ReconciliationWebhookTest`, `BillingLifecycleTest`, `P0HomologationTest::testA15...` | `applied=true` uma vez, auditoria única, `processing` sem acesso |
| A16 | Parcela atrasada identificada sem cancelamento automático | `BillingLifecycleTest::testOverdueSuspendsAccessWithoutCancelingTheAnnualContract`, `P0HomologationTest::testA16...` | `access_status=suspended`, `status=past_due`, `canceled_at=null` |
| A1–A9 | Fluxos anteriores | Testes deFeature por card | Ver PRs #2–#16 |

## Comando de execução

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=PilotSeeder
php artisan test --testsuite=Feature
```

## Evidências pendentes de emissão no CI

- Log completo de `php artisan test --testsuite=Feature` com todos os cenários verdes.
- Verificação de que nenhuma PII aparece em logs técnicos (`DataRightsTest` já asserting audit metadata).
- Confirmação de que o bloqueio financeiro precede qualquer chamada de LLM (fake gateway com `calls=0`).
