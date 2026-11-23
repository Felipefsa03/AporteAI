# Snapshot de mensagens — piloto fictício

Ambiente descartável, WhatsApp bot simulado, nenhum envio real. LLM fake com `calls=0`.

## Entrada: Fazer aporte R$ 800

- action: `blocked`
- allow_llm: false
- llm_calls: 0
- reply: Olá, Investidor! 👋
Identificamos que sua assinatura da **AporteAI MVP** venceu em 16/11/2026 e o pagamento ainda está pendente.
Por isso, os serviços de análises, aportes e relatórios estão temporariamente pausados.
Responda **Regularizar pagamento** e enviaremos as orientações para quitar sua assinatura.
Se você já pagou, responda **Já paguei** para verificarmos. Se precisar de ajuda, envie **Falar com atendimento**.
Seu histórico permanece preservado conforme nossas políticas e obrigações legais.

## Entrada: Meu plano

- action: `command`
- allow_llm: false
- llm_calls: 0
- reply: Plano: R$ 29,90/mês. Vencimento: 16/11/2026. Status: suspended.

## Entrada: Já paguei

- action: `command`
- allow_llm: false
- llm_calls: 0
- reply: Recebemos sua solicitação, Investidor. Seu pagamento ainda está em verificação. Para evitar informações incorretas, vamos conferir o status antes de reativar os serviços. Se precisar, digite **Falar com atendimento**.

## Entrada: Falar com atendimento

- action: `command`
- allow_llm: false
- llm_calls: 0
- reply: Atendimento humano pelo WhatsApp comercial: +15416221559.

## Resultado agregado

- Chamadas ao LLM: 0
- access_status: `suspended`
