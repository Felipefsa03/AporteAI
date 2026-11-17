# ADR-0001 — Monólito modular para o MVP AporteAI

- **Status:** Aceito em S0 (2026-11-17)
- **Escopo:** fundação arquitetural do MVP v1.2
- **Origem:** PRD AporteAI v1.2 §6, §8, §12 e §22

## Contexto

O MVP possui um único operador econômico, uma única credencial humana no dashboard, nenhuma conta web para investidores e dois canais WhatsApp distintos: o número comercial, manual, e o número do bot, integrado à Meta Cloud API. As regras regulatórias e financeiras (RB-001, RB-023, RB-028 a RB-033) exigem que elegibilidade, contrato, cobrança e opt-in sejam avaliados **antes** de qualquer chamada ao LLM ou serviço pago. Também é necessário separar proposta de execução, manter aritmética exata em centavos e produzir auditoria íntegra.

Nesse estágio não há multiempresa, self-service, portal do investidor nem múltiplos operadores. Introduzir microsserviços ou um portal acrescentaria complexidade sem entregar requisito P0.

## Decisão

Adotar **monólito modular** com fronteiras explícitas entre domínios, persistência PostgreSQL, filas Redis e workers assíncronos.

### Componentes

| Componente | Responsabilidade | Tecnologia prevista |
|---|---|---|
| `api` | API `/api/v1`, regras de domínio, workers, outbox | Laravel (PHP) |
| `admin` | Dashboard exclusivo do proprietário, uma conta com MFA | Laravel (Blade/Inertia) |
| `reporting` | Cálculos e geração de PNG enviados pelo bot | worker isolado (PHP ou Python) |
| `contracts` | OpenAPI, schemas de eventos e contratos internos | arquivos versionados |
| Fila | Eventos, retries limitados, dead-letter queue | Redis + workers Laravel |
| Banco | Dados transacionais, eventos, auditoria | PostgreSQL |
| WhatsApp | Webhooks assinados e envio pelo número do bot | Meta Cloud API |
| LLM/RAG | Apenas explicação da proposta já calculada | gateway adaptador |

### Módulos de domínio

`AdminAuth`, `ClientCRM`, `Commerce`, `Activation`, `WhatsAppGateway`, `SuitabilityCompliance`, `Portfolio`, `MarketData`, `Advisory`, `AIKnowledge`, `Reporting`, `AuditPrivacy`.

Cada módulo expõe contratos internos tipados. Nenhum módulo de recomendação pode gravar posições: recomendações são propostas; apenas `TransactionConfirmed` altera a carteira (RB-011).

### Ordem obrigatória do processamento de mensagens

A sequência abaixo é um controlador de frontend, não uma sugestão de prompt:

1. verificar assinatura do webhook e registrar `webhook_events` (idempotência, RB-016);
2. autenticar/vincular o investidor e localizar a conversa;
3. consultar assinatura, contrato e cobrança — `GET /internal/subscriptions/{investor_id}/entitlement`;
4. classificar comandos administrativos e direitos do titular (permitidos mesmo no bloqueio, RB-030);
5. se `past_due` ou `suspended_nonpayment`, responder a mensagem-padrão de vencimento e **encerrar sem chamar LLM, motor de recomendação ou gerador de PNG** (RB-028, RB-029, A13);
6. somente com acesso elegível, processar a conversa e acionar orquestração/LLM.

O bloqueio financeiro vive em `Commerce`/`WhatsAppGateway` e é revalidado no limite de cada serviço pago. Uma checagem apenas no botão do dashboard não atende ao PRD.

### Regras de implementação

- Valores monetários em `numeric(18,2)`; percentuais em basis points com soma exata de `10_000` (RB-007, RB-008, A4). Nunca usar ponto flutuante para dinheiro.
- Preços, vencimentos e indicadores vêm de `MarketData` com `as_of`, `source_id` e `quality_status` (RB-012, RB-019, A5). O LLM não inventa ticker, cotação ou fonte (RB-010).
- Universo elegível e limites vêm de `allocation_policies` versionadas e aprovadas; o LLM apenas explica a proposta já validada (§6.2).
- Eventos usam *at-least-once* com idempotência, `outbox`, `trace_id`, retries limitados e DLQ (§8).
- A feature flag `personalized_recommendations_enabled` nasce desligada e só pode ser avaliada após os gates C01–C07; ausência de autorização resulta em `no_recommendation`, nunca em recomendação genérica disfarçada de específica.
- Logs não contêm PII, tokens de acesso nem conteúdo de mensagens; auditoria registra ator, ação, sujeito, `trace_id` e timestamp.

## Consequências

**Positivas:** uma única base transacional simplifica consistência e auditoria; o bloqueio pré-LLM tem ponto único de aplicação; frontezas modulares permitem testar compliance sem acionar IA.

**Negativas:** o monólito exige disciplina de frontezas para não virar acoplamento; workers de PNG e LLM precisam ser isolados por fila para não degradar a confirmação de webhook; o gateway de pagamentos e o provedor de preços permanecem dependências externas com falha parcial.

## Alternativas rejeitadas

- **Microsserviços por domínio:** custo operacional desproporcional para operador único e exigência de consistência transacional.
- **Portal do investidor:** explicitamente fora de escopo (RB-021, ADR-006).
- **LLM como decisor de alocação:** viola determinismo, proveniência e auditabilidade exigidos no §6.2 e A4/A5.
