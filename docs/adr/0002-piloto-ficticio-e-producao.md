# ADR-0002 — Separação entre piloto fictício e produção regulada

- **Status:** Aceito em S0 (2026-11-17)
- **Escopo:** ambientes, dados e critério de liberação
- **Origem:** PRD AporteAI v1.2 §3, §14, §19, §22

## Contexto

O PRD define duas frentes independentes: engenharia (12 semanas de planejamento) e habilitação regulatória (gates C01–C07). Um piloto técnico pode — e deve — rodar com dados fictícios antes de qualquer autorização comercial. Misturar dados de teste com cadastros reais, números WhatsApp de produção ou cotações licenciadas cria risco de envio indevido e de evidência contaminada.

O checkpoint anterior do card ARC afirmava uma PR já integrada em outro caminho; no clone usado nesta sessão (`/home/felipelima/AporteAI`, `722b07b`) não há esse código. A documentação abaixo vale para o estado real conferido aqui.

## Decisão

Manter **dois domínios de execução com fronteira dura de dados e credenciais**:

| Domínio | Objetivo | Dados | Canais | Gates |
|---|---|---|---|---|
| **Piloto técnico** | validar fluxos P0, bloqueios, auditoria e resiliência | sintéticos/fictícios, prefixo `FICT-`, cotações de fixture | números de teste da Cloud API, gateway em sandbox, LLM com orçamento isolado | não depende dos gates; recomendação individualizada permanece desligada |
| **Produção regulada** | operação comercial somente após habilitação | dados reais de investidores e preços licenciados | número oficial do bot; número comercial permanece humano | exige C01–C07 aprovados e documentados |

### Regras de separação

1. Bancos, buckets, filas, chaves e segredos são distintos. Nenhuma credencial de produção existe no piloto e vice-versa.
2. O dataset fictício é versionado e inclui casos de borda: inadimplência, opt-in ausente, perfil vencido, cotação vencida, webhook duplicado e RAG hostil (§16).
3. Mensagens proativas no piloto usam apenas números de teste e templates aprovados para a conta de teste; opt-in fictício é registrado com a mesma trilha exigida em produção.
4. `personalized_recommendations_enabled` é `false` por padrão em ambos. No piloto, a flag pode ser ligada apenas com estrutura fictícia isolada para exercitar o desenho técnico; em produção, somente após parecer jurídico e evidências dos gates.
5. Artefatos de evidência (testes, capturas, relatórios) identificam o ambiente. Evidência de piloto **não** comprova prontidão regulatória.
6. Rollback do piloto: desligar flag, drenar filas, restaurar snapshot fictício. Rollback de produção: desligar flag, suspender envios, preservar dados e auditoria — nunca apagar registros por obrigação aplicável.

## Critérios de go/no-go (PRD §19)

**Piloto técnico:** fluxos P0 funcionam com dados fictícios; auditoria e bloqueios existem; provas de segurança são satisfatórias; fontes são verificáveis; testes passam.

**Consultoria personalizada comercial:** adicionalmente, estrutura regulada e responsáveis habilitados, contratos e políticas aprovados, licenciamentos regularizados, condições do WhatsApp atendidas, revisão humana e resposta a incidentes operacionais, e validação formal de compliance.

## Nota temporal

A Resolução CVM 250 foi publicada em 09/11/2026 e passa a vigorar em 01/12/2026, alterando certificações reconhecidas pela Resolução CVM 19. A documentação de habilitação deve considerar a data de efetiva operação; esta ADR não é parecer jurídico nem verificação de registro.

## Consequências

Permite avançar a engenharia sem antecipar decisão legal. O custo é manter dois conjuntos de configuração e fixtures, com checagens automatizadas para impedir vazamento de dados reais para o piloto e de fixtures para produção.
