# OPS · Runbook do piloto fictício

Escopo isolado: piloto técnico, dados fictícios, sem cloud paga, sem exposição de produção e sem aprovação de C01–C07/GO.

## Configuração

Copiar `.env.ops.example` para o ambiente de execução e preencher `OPS_TOKEN` e `OPS_BACKUP_PASSPHRASE` apenas fora do repositório. Sem `OPS_TOKEN`, os endpoints `/ops/*` retornam 404.

Processos locais:

```sh
php artisan migrate --force
php artisan queue:work database --tries=3 --backoff=10,30,60 --sleep=1
php artisan schedule:work
```

`QUEUE_CONNECTION=database` é obrigatório para o worker; `CACHE_STORE=database` mantém métricas sem dependência externa.

## Observabilidade

- `GET /ops/health` — status agregado de banco, fila e DLQ, com metas RPO/RTO/p95 e o estado de `public_recommendations`.
- `GET /ops/metrics` — texto Prometheus com contadores de alertas, `failed_jobs`, DLQs, outbox e assinaturas ativas.
- `POST /ops/alerts/test` — alerta sintético gravado em `audit_events` como `ops.alert.synthetic`; nenhum envio externo.

Todos exigem o cabeçalho `X-Ops-Token`. Logs passam por `App\Support\RedactingProcessor`, que remove senhas, tokens, e-mails, telefones e IPs antes dos handlers.

Limiares padrão: 20 `failed_jobs` ou 20 DLQs pendentes trocam o health para `degraded`. Meta de p95: 800 ms; RPO: 15 min; RTO: 60 min.

Medição local de latência:

```sh
OPS_BASE_URL=http://127.0.0.1:8099 OPS_TOKEN=... OPS_SAMPLES=20 scripts/ops/probe.sh
```

## Fila, DLQ e repetição

- Jobs usam `database` com tentativas e backoff configurados em `config/whatsapp.php`.
- Falha permanente gera `whatsapp_dead_letters` com `dedupe_key` única.
- Reprocessamento controlado:

```sh
php artisan ops:replay-dead-letters --dry-run
php artisan ops:replay-dead-letters --limit=50
```

O comando só reprocessa DLQs cuja outbox esteja `dead` e sem `provider_message_id`. Grava `replayed_at`/`replay_count`, tornando a segunda execução um no-op.

## Backup e restauração

```sh
BACKUP_DIR=/tmp/opencode/backups OPS_BACKUP_PASSPHRASE=... scripts/ops/backup.sh
BACKUP_FILE=<arquivo.gpg> PGDATABASE=<db-descartavel> \
  OPS_BACKUP_PASSPHRASE=... OPS_CONFIRM_RESTORE=1 scripts/ops/restore.sh
```

Backups são `pg_dump -Fc` cifrados com AES256 e acompanhados de SHA-256. Nunca restaurar em banco de produção sem decisão humana.

## Runbooks de incidente

| Sintoma | Ação |
| --- | --- |
| Worker morto | Reiniciar `queue:work`; conferir `jobs`/`failed_jobs`; o job é idempotente e reprocessa sem duplicar. |
| Provedor falhando | Tentativas e backoff são automáticos; após esgotar, a mensagem vai para a DLQ. Manter bot em verificação, nunca presumir dívida. |
| DLQ crescendo | Rodar dry-run, investigar `reason`, corrigir causa e executar replay; alertar se ≥ 20 pendências. |
| Banco indisponível | `/ops/health` fica `down`; restaurar em banco descartável, validar contagens e só então reabilitar worker. |
| Latência alta | Rodar `probe.sh`, comparar p95 com 800 ms, revisar fila e provedores antes de qualquer mudança. |

## Auditoria de dependências e permissões

- Nenhum segredo no repositório; apenas nomes de variáveis em `.env.ops.example`.
- Provedores de WhatsApp e LLM ficam em modo mock por padrão; `public_recommendations` é constante `false`.
- O painel é a única interface administrativa; `/ops/*` exige token separado e não retorna PII.
- Rate limits de login e webhook continuam nos domínios existentes; alertas só registram contadores agregados.

## Ensaios executados em 2026-11-23

Ambiente descartável: PHP 8.3, PostgreSQL local, `QUEUE_CONNECTION=database`, servidor em `127.0.0.1:8100`, branch `feat/aporteai-ops-observability`.

| Ensaio | Resultado |
| --- | --- |
| Suíte completa isolada da branch OPS | `OK (142 tests, 930 assertions)`; `php -l` sem erros. |
| `/ops/health` sem token | HTTP 404 (endpoint não existe para quem não tem token). |
| `/ops/health` com token | HTTP 200, `checks.database=ok`, `queue=ok`, `dead_letters=ok`, `public_recommendations=false`. |
| `POST /ops/alerts/test` | HTTP 202, gravado em `audit_events` como `ops.alert.synthetic`; métrica `ops_alerts_total_24h 1`. |
| Sonda de latência (20 amostras) | `p50=70 ms`, `p95=79 ms`, `max=84 ms` — abaixo da meta de 800 ms. |
| Worker morto com `kill -9` e reiniciado | Fila voltou a zero, `failed_jobs=0`, recuperação em 4 s; job idempotente não duplicou trabalho. |
| Replay de DLQ | Teste automatizado com `Queue::fake()`: duas execuções produzem um único `SendWhatsAppOutbox` e `replay_count=1`. |

## Limitações do executor deste card

- `pg_dump`/`pg_restore` não estão disponíveis no sandbox, então backup cifrado e restore não foram executados aqui; os scripts foram fornecidos para o ambiente do proprietário.
- RPO/RTO e p95 são metas configuradas e medidas localmente; o restore completo e a medição em carga real dependem do ambiente do proprietário.
- Não há integração externa de paging; o alerta sintético é gravado localmente.
- Não foi provisionada cloud paga nem exposta produção.
