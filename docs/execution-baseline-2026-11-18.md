# Adendo de base executável — 18/11/2026

Este adendo registra o estado auditado da base técnica após as entregas CI e REG.
Ele **não** substitui as decisões históricas de `docs/homologation-report.md`,
`docs/readiness-report.md` ou `docs/plano-execucao-mvp.md`; ele as atualiza no tempo.
Afirmações antigas de que o executor não tinha PHP/Composer/PostgreSQL eram válidas
na data em que foram escritas e não descrevem mais este ambiente.

## Ancestralidade e integração

- `main` auditada em `2c5673b`, igual a `origin/main` após `fetch --prune`.
- PR #19 (`ci/aporteai-tests`) foi fechado sem merge; seus commits permanecem no
  histórico da branch CI.
- PR #20 (`fix/aporteai-regressions`) foi mesclado apenas em
  `origin/ci/aporteai-tests` (`d69987d`), não em `main`.
- A cadeia é `2c5673b → fad2757 (CI) → 460e415 (REG) → d69987d (merge PR #20)`.
  O diff `main..d69987d` contém 33 arquivos, +8165/−41 linhas, incluindo workflow,
  lockfile, migração de auditoria, correções e evidências.
- A integração é preparada em branch curta e PR para `main`, sem push direto na trunk,
  sem automerge e sem remoção de branches ou das cinco tags `backup/*`.

## Gate executado na base integrada

Commit verificado: `d69987d`. Ambiente local: PHP 8.4.27, Composer 2.10.3,
PostgreSQL 16.10 em instância descartável, isolada por socket, sem credenciais de
produção e sem exposição em rede.

| Verificação | Resultado |
| --- | --- |
| `composer validate --strict` | aprovado |
| `composer install` a partir do lockfile | aprovado |
| `composer check-platform-reqs` | aprovado |
| `php -l` em 164 arquivos | aprovado |
| `git diff --check` | aprovado |
| Suíte completa PHPUnit/PostgreSQL 16 | 71 testes, 328 assertions, zero falhas/erros |

O workflow de CI usa PHP 8.3/PostgreSQL 16. O run `44356943367` está aprovado no
commit `460e415`, que **não é** `main`. O resultado do commit integrado deve ser
confirmado no PR antes de qualquer validação humana. Uma suíte verde não é
homologação integrada nem autorização comercial.

## Riscos da migração de auditoria

`database/migrations/2026_11_18_000031_allow_named_audit_identifiers.php` altera o
tipo de `actor_id` para aceitar UUIDs e identificadores nominais de serviços. Em
produção, `ALTER TYPE` pode bloquear `audit_events`; revisar janela, plano de
rollback e monitoramento antes de aplicar. O rollback para UUID recusa
identificadores nominais e não deve apagar registros de auditoria: prefira correção
adiante. Nenhuma migração foi aplicada a ambiente de produção nesta entrega.

## Limites que permanecem

C01–C07 e GO continuam sob decisão humana; recomendações públicas permanecem
desabilitadas. Não há portal do investidor, execução de ordens, transferência de
repositório, contratação paga ou envio real a clientes. A interface visual do
proprietário, o envio de texto/mídia pelo WhatsApp, um provedor LLM real e os textos
desenhados no PNG seguem como lacunas com cards próprios. A validação do card BASE e
o merge em `main` são atos humanos.
