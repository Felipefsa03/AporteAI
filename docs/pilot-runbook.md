# Runbook do piloto técnico

## Princípios

- Piloto usa **somente dados fictícios** (`PilotSeeder`).
- Recomendações públicas permanecem desabilitadas (`config/pilot.php`).
- O bloqueio financeiro é avaliado **antes** de qualquer inferência.
- A decisão comercial (card GO) continua sendo humana e depende dos sete gates.

## Preparação

```bash
cp .env.example .env
# Opcional: PILOT_OWNER_PASSWORD_HASH=<hash bcrypt gerado fora do repositório>
php artisan key:generate
php artisan migrate --fresh --seed --class=PilotSeeder --force
```

## Jornada controlada

1. **Acesso:** sem sessão admin/MFA, `GET /api/v1/admin/investors` deve retornar 401.
2. **Ativação:** opt-in e ativação pelo número bot `+5575992605555`; número comercial humano `+15416221559` apenas em handoff.
3. **Bloqueio:** para o investidor suspenso, enviar `Fazer aporte R$ 800`; esperar apenas aviso de vencimento e `llm_calls=0`.
4. **Direitos no bloqueio:** `Meus dados`, `Exportar dados`, `Falar com atendimento` e `Já paguei` devem responder sem alterar `access_status`.
5. **Reconciliação:** webhook `paid` assinado aplica uma vez; duplicata e `processing` não reaplicam.
6. **Revisão crítica:** caso `pilot_suspended_nonpayment` fica pendente; tentativa de resolução com papel não autorizado ou outcome `release` deve falhar.
7. **Relatório:** gerar PNG `REALIZADO` para a carteira com compra confirmada; conferir rótulo, timestamp e valores.
8. **Fontes:** verificar que cotações do piloto têm fonte, data e versão; remover uma cotação e confirmar recusa da explicação.
9. **Auditoria:** conferir `audit_events` para abertura/resolução de revisão, reconciliação e direitos do titular.

## Incidentes ensaiados

| Incidente | Resposta esperada |
| --- | --- |
| Gateway de pagamento indisponível | `verification`, escalate em auditoria, nenhuma liberação |
| Webhook duplicado | `applied=false`, nenhuma mensagem repetida |
| Fonte de dados vencida | alocação cai para CASH e explicação é recusada |
| LLM sem configuração | `review_required`, nenhuma chamada externa |
| Caso crítico pendente | proposta/ordem retornam 423 |

## Evidências

Anexar ao card PIL: saída da jornada, log da suíte `P0HomologationTest`, capturas do dashboard com dados fictícios e o relatório de prontidão.

## Adendo 18/11/2026

O bloco "Preparação" acima é histórico. Para reproduzir o piloto em checkout limpo,
use [ambiente local](local-environment.md): `scripts/bootstrap-local.sh` não cria
`.env` no repositório, mantém a `APP_KEY` em arquivo externo modo `600` e usa
PostgreSQL 16 por socket privado. `scripts/verify-local-queue.sh` exercita o worker
em fila `probe-*` isolada; a fila `whatsapp` e gates humanos não são acionados.
