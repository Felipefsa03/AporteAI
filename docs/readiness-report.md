# Relatório de prontidão do piloto técnico

## Escopo

Piloto controlado do MVP AporteAI v1.2 com dados fictícios. Não é liberação comercial.

## Evidênciasimplementadas

| Área | Evidência | Status |
| --- | --- | --- |
| Bloqueio financeiro antes do LLM | `BillingGateTest`, `ProposalExplainerTest`, `P0HomologationTest` | Implementado; execução no CI pendente |
| Idempotência de webhook | `ReconciliationWebhookTest`, `BillingLifecycleTest` | Implementado; execução no CI pendente |
| Alocação determinística | `AllocationEngineTest` (10.000 bps e centavos exatos) | Implementado; execução no CI pendente |
| Carteira realizada | `PortfolioLedgerTest`, `ReportGeneratorTest` | Implementado; execução no CI pendente |
| Revisão humana | `HumanReviewTest` (papel, motivo, sem liberação de bloqueio) | Implementado; execução no CI pendente |
| Privacidade | `DataRightsTest` (exportação, retenção, sem PII em auditoria) | Implementado; execução no CI pendente |
| Fontes e versões | `MarketDataIngestionTest`, `AllocationEngineTest` | Implementado; execução no CI pendente |
| Rollback | `docs/rollback.md` e `PilotSeeder` | Implementado; ensaio no CI pendente |

## Riscos em aberto

1. Execução real de PHPUnit/PostgreSQL indisponível neste executor; o CI precisa confirmar a suíte verde.
2. CI não foi adicionado ao repositório porque o token disponível não possui escopo `workflow`.
3. Os sete gates C01–C07 e a decisão GO dependem de formalização jurídica/compliance humana.
4. O LLM de produção não está configurado; o comportamento padrão é `review_required`.

## Conclusão do executor

O código do piloto, os runbooks e o plano de rollback estão entregues. A prontidão comercial **não** pode ser afirmada por este executor: ela depende de execução verde no CI, revisão humana e decisão formal do card GO.

## Adendo 18/11/2026

Os itens 1 e 2 de "Riscos em aberto" descreviam o ambiente da redação original e
foram superados pela base integrada: runtime local disponível, CI versionado e suíte
executada com evidência. O estado auditado, o resultado do gate e os riscos restantes
da migração de auditoria estão em
[base executável](execution-baseline-2026-11-18.md). Os itens 3 e 4 permanecem,
assim como a conclusão: prontidão comercial depende de decisão humana.

## Adendo 23/11/2026 — homologação integrada do piloto fictício

A tabela acima registra a implementação por serviço isolado; **não** deve ser lida como
produto pronto. No checkout integrado do piloto fictício (branch
`feat/aporteai-e2e-homologacao`, merge `a3b017b`), o gate local executou
`OK (152 tests, 980 assertions)` com PHP 8.3 e PostgreSQL descartável em UTC, sem
skips silenciosos. A matriz requisito → teste → evidência → commit está em
[homologação E2E](evidence/e2e-homologacao.md); os ensaios operacionais estão em
[runbook OPS](ops-runbook.md).

Continuam fora do escopo deste executor, e portanto fora de qualquer afirmação de
prontidão: restauração real com `pg_dump`/`pg_restore`, Meta Cloud API com
destinatário autorizado, paging externo, publicação no GitHub (sem `gh`/credencial
neste ambiente), cloud paga e decisão formal dos gates C01–C07/GO.
`public_recommendations` permanece `false`. A suíte verde é pré-requisito técnico,
não homologação comercial.
