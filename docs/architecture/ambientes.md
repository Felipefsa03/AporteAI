# Ambientes, segredos e critérios de promoção

## Inventário

| Ambiente | Finalidade | Dados | WhatsApp | Cobrança | Dados de mercado | Flags |
|---|---|---|---|---|---|---|
| `local` | desenvolvimento individual | fixtures locais | nenhum envio real | sandbox ou stub | fixtures versionadas | todas desligadas por padrão |
| `development` | integração entre módulos | sintéticos | números de teste Meta | sandbox | fixtures + fontes públicas sem redistribuição | `personalized_recommendations_enabled=false` |
| `piloto` | homologação técnica com dados fictícios (ADR-0002) | `FICT-*`, nunca PII real | números de teste, templates aprovados | sandbox com conciliação simulada | fixtures e séries públicas licenciadas para teste | flag pode ser ligada apenas no domínio isolado |
| `producao` | operação comercial após gates | dados reais de investidores | número oficial do bot; comercial é humano | gateway real | provedor com direito de uso | flag somente após C01–C07 e validação formal |

Um mesmo deploy **nunca** serve piloto e produção. Nomes de ambiente fazem parte de toda configuração e de toda evidência.

## Configuração e segredos

- Segredos em cofre/KMS do provedor, injetados por ambiente; nada em repositório, log ou checkpoint do card.
- Chaves de assinatura de webhook, credenciais do gateway, token da Cloud API, chave do provedor de preços e chave do LLM são distintas por ambiente e rotacionáveis sem deploy de código.
- Criptografia em repouso para dados pessoais e mensagens; TLS em trânsito; backup criptografado com restore testado antes do go-live (§12).
- Logs sem PII, sem conteúdo de mensagem e sem segredo. Correlação por `trace_id`, nunca por CPF/telefone.
- Um único usuário humano no dashboard com MFA; nenhuma conta de investidor e nenhum Painel multioperador (RB-015, RB-026).

## Banco e migrações

- PostgreSQL com migrações versionadas, uma cadeia por release; nenhuma migração destrutiva sem plano de rollback.
- Dados monetários em `numeric(18,2)`; percentuais em basis points; snapshots de mercado e de perfil são imutáveis.
- Campos de PII são classificados por finalidade, com política de retenção e exclusão registrada em `privacy_requests` (RB-030, A9).
- Antes de promover: conferir que o schema do ambiente de destino corresponde à migração esperada e que não existem registros `FICT-*` em produção.

## Filas eworkers

- Confirmação de webhook é rápida; processamento pesado (PNG, LLM, conciliação, reindexação de RAG) vai para fila.
- `at-least-once` + idempotência por chave natural; retries limitados com backoff e `dead-letter queue`.
- Eventos de negócio são publicados via `outbox` após commit; reprocessamento não duplica cobrança, recomendação, operação ou mensagem crítica (RB-016).
- Métricas de fila: profundidade, idade do item mais antigo, taxa de DLQ e tempo de processamento por tipo.

## Promoção de mudanças

1. Branch curta e tipada a partir de `main`; convenção `type(scope): imperative summary` com escopo estável do projeto.
2. Checagens direcionadas durante a iteração; gate completo exigido uma vez antes da integração.
3. Um pull request por entrega coerente; alterações relacionadas são agrupadas, commits permanecem atômicos.
4. Mudanças de alto risco — bloqueio financeiro, suitability, alocação, autenticação, privacidade — exigem revisão humana.
5. Nunca publicar diretamente em `main`. Corpo de PR multilinha via `gh --body-file`, verificado com `gh pr view`.
6. Promoção entre ambientes é explícita: `development → piloto → producao`, com evidência de testes e aprovação dos gates aplicáveis.

## Observabilidade

- Traces por `trace_id` do webhook até a resposta e o evento de auditoria.
- Alertas: DLQ crescente, webhook rejeitado por assinatura, entitlements indisponíveis, cotação vencida, latência p95 acima da meta interna (30 s), custo por mensagem/token e tentativas de acesso entre investidores.
- Painel de indicadores do proprietário: funil, recomendações bloqueadas, dados vencidos, divergências de carteira, MRR, custo de IA/WhatsApp (PRD §18).

## Rollback

- **Piloto:** desligar flag, drenar filas, restaurar snapshot fictício; nenhum efeito em dados reais.
- **Produção:** desligar `personalized_recommendations_enabled`, suspender envios do bot, preservar dados e auditoria, escalar ao proprietário. Reativação depende de causa corrigida e evidência registrada; nunca apagar registros sujeitos a retenção.
