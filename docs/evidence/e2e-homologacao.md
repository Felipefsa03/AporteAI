# E2E · Homologação integrada do piloto fictício

Data: 2026-11-23. Baseline integrado: merge commit `a3b017b` (`feat/aporteai-e2e-homologacao`), que contém os commits do painel do proprietário e da observabilidade operacional.

## Gate executado

```sh
# checkout limpo da branch integrada, PHP 8.3 + PostgreSQL descartável em UTC
find app bootstrap config database routes tests -name '*.php' -print0 | xargs -0 -n1 php -l
php -d memory_limit=512M vendor/bin/phpunit -c phpunit.local.xml
# OK (152 tests, 980 assertions)
```

Nenhum teste foi pulado silenciosamente: a execução terminou com `OK` e sem `S`/`skipped`. O comando é o equivalente local do gate do CI; o workflow versionado continua em `.github/workflows/ci.yml` e a publicação depende de credencial GitHub humana.

## Matriz PRD A1–A16 → teste → evidência

| Req. | Critério | Cobertura executada | Resultado |
| --- | --- | --- | --- |
| A1 | Onboarding retomável e idempotente | `OnboardingFlowTest::testA1OnboardingResumesAndIsIdempotent`, `BotActivationTest` | OK |
| A2 | Fase 1 obrigatória com quatro respostas | `AporteFlowTest::testA2AnsweringFourQuestionsMovesToPhaseTwoAndIsIdempotent`, `testA2PhaseOneIsMandatoryAndNeedsFourAnswers`, `ConversationJourneyTest` | OK |
| A3 | Estrutura não autorizada/perfil inválido sem recomendação | `OnboardingFlowTest::testA3UnauthorizedStructureBlocksAnyInstrumentRecommendation`, `AllocationEngineTest::testA3UnauthorizedAdvisoryProducesNoSpecificInstrument`, `ProposalExplainerTest::testBlockedProposalNeverCallsLlm` | OK |
| A4 | Alocação exata em bps e centavos | `AllocationEngineTest::testA4AllocationSumsExactlyTenThousandBpsAndEightHundredReais`, `AporteFlowTest::testA4A6ProposalIsExactDoesNotMovePositionsAndConfirmationAppliesOnce` | OK |
| A5 | Proveniência, validade e RAG hostil | `MarketDataIngestionTest`, `ProposalExplainerTest::testValidProposalIsExplainedWithProvenanceAndHostileTextIsIgnored`, `ProposalExplainerTest::testStaleSourceIsNotExplained` | OK |
| A6 | Proposta não move posição; confirmação aplica uma vez | `AporteFlowTest::testA4A6ProposalIsExactDoesNotMovePositionsAndConfirmationAppliesOnce`, `PortfolioLedgerTest` | OK |
| A7 | PNG de realizado com timestamp e aviso de dado incompleto | `ReportGeneratorTest` (4 testes); snapshot em `report-realizado.png` | OK |
| A8 | Revisão humana com papel, motivo e trilha | `HumanReviewTest::testA8CriticalCaseStoresSnapshotAndRequiresAuthorizedRoleAndReason`, `testPendingCriticalCaseBlocksExecution` | OK |
| A9 | Export/correção/exclusão com retenção e sem PII em log | `DataRightsTest`, `OpsObservabilityTest::testProcessadorDeLogsRedigeSegredosEPii` | OK |
| A10 | Somente proprietário; registros não se cruzam | `AdminDashboardTest`, `OwnerPanelTest`, `SafeInvariantsTest::testEndpointHttpNaoUsaSuitabilityDeOutroInvestidor` | OK |
| A11 | Mesmo produto nos dois preços; bloqueio pago preserva direitos | `BillingLifecycleTest`, `BillingGateTest`, `OwnerPanelTest::testTodasAsPaginasDoPainelRenderizam` | OK |
| A12 | Bot em número distinto, opt-in e handoff humano | `WhatsAppTransportTest`, `WhatsAppTransportTest::testHumanHandoffUsesCommercialNumberAndSkipsLlm`, `BotActivationTest` | OK |
| A13 | Inadimplente recebe só aviso; zero chamadas de LLM | `BillingGateTest::testA13OverdueMessageNeverCallsLlm`, `P0HomologationTest`; snapshot em `mensagens-piloto.md` | OK |
| A14 | Autosserviço no bloqueio sem liberar serviço pago | `BillingGateTest::testA14AllowedCommandsWorkWithoutPaidServices`, `DataRightsTest::testA14RightsWorkDuringBillingBlockWithoutUnlockingPaidServices` | OK |
| A15 | Webhook `paid` válido reativa uma vez; duplicata/`processing` não | `ReconciliationWebhookTest`, `P0HomologationTest::testA15SignedPaidWebhookReactivatesOnceAndProcessingIsIgnored` | OK |
| A16 | Parcela anual atrasada sem cancelamento automático | `BillingLifecycleTest::testOverdueSuspendsAccessWithoutCancelingTheAnnualContract`, `P0HomologationTest::testA16OverdueInstallmentIsIdentifiedWithoutAutomaticCancellation` | OK |

## Matriz RB (famílias de invariantes) → teste

Os identificadores RB completos estão documentados em `docs/product/politica-produto.md` e nas regras de domínio. O agrupamento abaixo é por família de risco, não substitui a leitura das regras.

| Família | Regras representadas | Cobertura | Resultado |
| --- | --- | --- | --- |
| Autorização e acesso único | RB-015, RB-021, RB-022, RB-026 | `SafeInvariantsTest`, `OwnerPanelTest`, `AdminDashboardTest` | OK |
| WhatsApp e consentimento | RB-023, RB-027 | `WhatsAppTransportTest`, `BotActivationTest` | OK |
| Pagamento e bloqueio | RB-002, RB-029, RB-030, RB-031, RB-032 | `BillingGateTest`, `BillingLifecycleTest`, `ReconciliationWebhookTest` | OK |
| Alocação, carteira e ordens | RB-007, RB-008, RB-011, RB-017, RB-020 | `AllocationEngineTest`, `PortfolioLedgerTest`, `AporteFlowTest` | OK |
| Privacidade e retenção | RB-018, RB-019, RB-024 | `DataRightsTest`, `OpsObservabilityTest`, `RetentionExecutionTest` | OK |
| Proveniência e fontes | RB-003, RB-004, RB-005, RB-006 | `MarketDataIngestionTest`, `ProposalExplainerTest` | OK |
| Operação e resiliência | RB-023 (DLQ), limites de provedor, redaction | `OpsObservabilityTest`, `LlmProviderTest`, worker kill rehearsal no runbook OPS | OK |

## Evidências anexadas

- `ui-login-mobile.png`, `ui-dashboard-mobile.png`, `ui-clients-mobile.png` — navegador no checkout integrado (viewport estreito).
- `mensagens-piloto.md` — transcrição de quatro interações do bot em bloqueio por inadimplência, com `llm_calls=0`.
- `report-realizado.png` — PNG gerado no checkout integrado com carteira realizada e timestamp.
- `docs/ops-runbook.md` — ensaios de healthcheck, alerta, latência, worker e replay de DLQ.

## Bloqueadores externos explícitos

- Restauração real com `pg_dump`/`pg_restore` não executada no sandbox; scripts e responsabilidade humana registrados no runbook OPS.
- Meta Cloud API real não testada: sem credencial e sem destinatário autorizado; modo mock permanece padrão.
- Publicação GitHub bloqueada: `gh` ausente e nenhuma credencial configurada neste executor.
- Nenhuma cloud paga, portal de cliente ou execução de ordens reais foi provisionada.
- C01–C07 e GO continuam abertos; `public_recommendations` permanece `false`. Este card prepara a decisão humana, não a toma.
