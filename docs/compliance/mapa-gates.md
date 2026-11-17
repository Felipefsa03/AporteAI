# Mapa de gates regulatórios e operacionais

Este mapa traduz os gates C01–C07 do PRD v1.2 §3 em controles de engenharia e evidências. **Não é parecer jurídico nem verificação de registro.** Status inicial em 2026-11-17: todos os cards de gate estão `open`.

| Gate | Exigência | Controle de engenharia | Evidência esperada | Card |
|---|---|---|---|---|
| C01 Operador autorizado | entidade autorizada, responsável pela consultoria e compliance registrados antes de habilitar recomendações | `operator_profile` com documento, `authorized_at` e `recommendations_enabled`; flag global desligada por padrão; checagem no limite de `Advisory` | parecer jurídico, registro da entidade, identidade do responsável, registro da decisão de habilitação | `fe6259c8-3c50-4e1f-a64a-799b6e3457c8` |
| C02 Contrato/disclosures | termos, remuneração, conflitos, uso de IA, riscos e canais de revisão | `advisory_contracts` versionado; `consent_records` por finalidade; textos enviados pelo bot com `document_version` | contrato assinado, versão dos disclosures, prova de entrega ao investidor | `3a2227c4-e939-46c7-964f-f1ec20d2e13a` |
| C03 Suitability | classificação vigente, evidências e bloqueios técnicos | `suitability_assessments` versionado com `expires_at`; verificação antes da Fase 2 (RB-003) e bloqueio quando `stale` | questionário, classificação, trilha de atualização, teste de perfil vencido | `4b249193-8729-42e1-a755-6d1f09c213c2` |
| C04 Aprovação metodológica | universo de ativos e metodologia validados e versionados | `instruments` com `status`, `allocation_policies` com `approved_by_reference` e `version`; monitoramento e escalonamento | metodologia aprovada, lista de ativos homologados, relatório de monitoramento | `a929ed57-126f-4a74-9563-c07408408421` |
| C05 LGPD | bases legais, minimização, direitos, fornecedores, incidentes | `consent_records`, `privacy_requests`, criptografia, retenção por finalidade, logs sem PII, `audit_events` | registro de tratamento, política de retenção, evidência de exportação/exclusão, plano de incidente | `1d921cae-1939-4ea5-8a5b-0400eb1ac008` |
| C06 WhatsApp | elegibilidade, templates, janela, automação e saída humana | `whatsapp_optins` com evidência, `activation_requests` com template aprovado, idempotência, número comercial separado e human handoff | aprovação de templates, registro de opt-in, prova de entrega, política de atendimento | `890b2b91-ee23-476d-ac0a-8847741fb9f6` |
| C07 Mercados e dados | direitos de redistribuição, licenças, SLAs e atualidade | `data_sources` com licença e `freshness_policy`; `market_quotes` com `as_of` e `quality_status`; bloqueio por fonte vencida (RB-019) | contratos de licença, política de atualidade, testes de cotação vencida | `57e66a42-d7fb-4014-97f6-6c85019103f4` |

## Gate transversal: bloqueio financeiro antes do LLM

Não é um gate regulatório do PRD, mas é requisito P0 (RB-028 a RB-033, FL-05, A13–A15) e deve ser tratado com o mesmo rigor:

- verificação de `entitlement` em toda mensagem recebida, antes de qualquer chamada ao LLM;
- `past_due`/`suspended_nonpayment` => mensagem de vencimento, sem serviço pago;
- comandos permitidos no bloqueio: `Meu plano`, `Já paguei`, `Regularizar pagamento`, `Falar com atendimento`, `Cancelar assinatura`, `Meus dados`, `Exportar dados`;
- quitação somente por evento conciliado do gateway ou baixa manual auditada; nunca por comprovante do cliente;
- reativação idempotente, sem apagar histórico e sem retomar automaticamente intenção de aporte interrompida.

Card de engenharia: `9df7d5f8-32cb-4de6-8cbb-792c457396c7` (BLO).

## Dependência de liberação

`personalized_recommendations_enabled` depende de C01–C04 aprovados; C05–C07 condicionam a operação como um todo. O card GO (`f832dc85-b591-4334-8751-8a81482166b8`) registra a decisão comercial final e não deve ser movido para "feito" sem evidência documental dos gates aplicáveis.

## Nota temporal

Resolução CVM 250 (publicada em 09/11/2026, vigência a partir de 01/12/2026) altera certificações reconhecidas pela Resolução CVM 19. A habilitação deve considerar a data de efetiva operação; não presumir que certificação individual autoriza pessoa jurídica, nem que o uso de IA dispensa registro (PRD §3).
