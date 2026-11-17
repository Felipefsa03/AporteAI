# Política de produto do MVP v1.2

Documento de referência para decisões de produto já confirmadas no PRD v1.2. Não altera contrato, parecer jurídico ou autorização regulatória.

## Decisões fechadas

| Tema | Decisão | Regra derivada |
|---|---|---|
| Interface do investidor | somente WhatsApp do bot | nenhum login, portal, miniapp ou senha de cliente (RB-021, A10) |
| Atendimento humano | número comercial do proprietário | canal manual, distinto do número automatizado (RB-027, A12) |
| Atendimento automatizado | segundo número da marca via Cloud API | webhook assinado, opt-in e template aprovado (RB-023) |
| Backoffice | dashboard exclusivo do proprietário, uma conta com MFA | nenhum painel multiusuário ou multiempresa (RB-015, RB-026) |
| Conversão | venda fechada diretamente com o proprietário | cadastro inserido manualmente no dashboard (RB-022) |
| Ativação | administrador registra e dispara onboarding | elegibilidade verificada e envio idempotente; sem opt-in não há mensagem proativa |
| Oferta | um produto, sem níveis de funcionalidade | mesmos recursos em qualquer modalidade (RB-025, A11) |
| Preço mensal | R$ 29,90/mês | cobrança recorrente mensal |
| Preço anual | 12 × R$ 19,90 = R$ 238,80 | modelo de cobrança/renovação a homologar no gateway; atraso de parcela não cancela contrato automaticamente (A16) |
| Regulação | CVM/suitability/revisão mantidos | recomendação personalizada desligada até habilitação formal |
| Execução | nenhuma ordem executada pela IA | operação é proposta até confirmação explícita do cliente (RB-011, RB-017) |

## Ativação de cliente

Pré-condições verificadas pelo dashboard antes de `Ativar cliente`: venda registrada, contrato/cobrança em situação elegível, opt-in de WhatsApp com origem e evidência, consentimentos aplicáveis. Se qualquer pré-condição faltar, o cliente permanece pendente e **nenhuma mensagem não autorizada é enviada**.

Sequência: `draft → sold → pending_payment → pending_opt_in → ready_for_activation → activation_queued → invited → pending_profile → active`, com estados de falha e reenvio idempotente (PRD §4.1).

## Modalidade e bloqueio financeiro

Mensal e anual são formas de contratar o mesmo pacote. Nenhuma funcionalidade é liberada por modalidade. Assinatura vencida e não conciliada bloqueia novos aportes, recomendações, relatórios pagos e atualizações assistidas, mas preserva suporte humano, informações de plano, cancelamento e direitos do titular (RB-029, RB-030).

`Já paguei` dispara verificação de conciliação ou atendimento humano; nunca desbloqueia por declaração do cliente (RB-031). Regularização reativa o acesso sem apagar conversa, carteira ou histórico e sem duplicar mensagens (RB-032).

## Aporte e recomendação

Toda intenção de aporte passa pela Fase 1 obrigatória, mesmo com perfil histórico válido (RB-002). A Fase 2 só ocorre se houver estrutura autorizada, contrato válido, perfil válido e compatível, dados de mercado atualizados e validação de compliance. Caso contrário: `no_recommendation`, atualização de perfil ou revisão humana — nunca recomendação de instrumento específico.

O resultado é `PROPOSTA`; a carteira `REALIZADA` só muda com operação confirmada pelo investidor. Percentuais somam exatamente 10.000 bps e valores em centavos são reconciliados sem sobra silenciosa (RB-007, RB-008).

## Decisões ainda abertas

As decisões abaixo continuam pendentes de validação e não devem ser antecipadas por código:

- `ADR-001` operador próprio registrado ou parceria autorizada;
- `ADR-002` universo inicial restrito ou ações/FIIs imediatamente;
- `ADR-003` provedor de preços e direitos de redistribuição;
- `ADR-004` gateway, renovação e cancelamento do plano anual parcelado;
- `ADR-005` revisão humana de 100% no beta ou limiares aprovados;
- `ADR-007` responsável técnico que aprova suitability e política de investimento.

`ADR-006` (sem portal), `ADR-008` (dois números), `ADR-009` (login único) e `ADR-010` (produto único) estão fechados conforme a tabela acima.
