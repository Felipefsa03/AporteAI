# Plano do piloto fictício — decisões de produto (23/11/2026)

Este documento registra as decisões de produto comunicadas pelo responsável para o piloto
fictício. **Nada aqui foi implementado ou alterado no Gartrol**; os gates C01–C07 e GO
continuam abertos e somente pessoas podem aprová-los. É contexto para os cards de painel,
bot simulado, operação e homologação.

## 1. Escopo e controle de acesso

- Manter o ambiente com dados fictícios, sem liberação comercial ou recomendações reais.
- Manter o painel exclusivo do proprietário.
- Somente o proprietário poderá cadastrar e remover clientes pelo painel; sem cadastro público.
- Preservar os controles de retenção e privacidade ao tratar remoções de clientes.

## 2. Contrato, cobrança e renovação — C02

### Planos e receita

- Mensal: R$ 29,90.
- Anual: R$ 238,80, em 12 × R$ 19,90.
- Mesmos recursos nos dois planos.
- Receita exclusivamente de assinaturas.

### Atraso

- Tolerância de três dias corridos após o vencimento, mantendo o acesso.
- Após esse prazo, atraso confirmado suspende apenas os serviços pagos, antes de qualquer
  chamada à IA.
- Atendimento, cancelamento e direitos de privacidade continuam disponíveis.
- Falha do provedor deixa o pagamento “em verificação”, sem presumir dívida.

### Renovação anual

- Renovação automática, claramente prevista no contrato.
- Um único aviso 30 dias antes, com valor, data e instruções para desativação.
- O cliente solicita a desativação pelo bot.
- O bot registra data e hora, confirma o recebimento e bloqueia imediatamente a renovação.
- O proprietário conclui pelo painel; o cliente recebe a confirmação final.
- Se a solicitação continuar pendente no vencimento, o plano encerra sem renovação nem nova
  cobrança.
- A solicitação não exclui o cadastro nem altera automaticamente as parcelas do contrato
  vigente.

### Documentação prevista

Preparar contrato e informações sobre preços, cobrança, cancelamento, uso de IA, riscos,
remuneração e atendimento. Rescisão antecipada do anual, reembolsos e redação jurídica final
permanecem pendentes de revisão.

## 3. Perfil do investidor e revisão humana — C03

- Questionário inicial com aprovação do proprietário pelo painel, no piloto.
- Validade do perfil: 12 meses.
- Antes de cada proposta, confirmar objetivo, prazo, tolerância a risco e mudanças financeiras.
- Reavaliar mudanças relevantes em objetivo, prazo, risco, renda, patrimônio, dívidas ou
  necessidade de liquidez.
- Usar regras predefinidas para sinalizar impacto; casos duvidosos seguem para análise do
  proprietário.
- A IA não poderá liberar o perfil.
- Perfil incompleto, vencido ou inconsistente bloqueia novas recomendações até resolução.

### Fila de revisão no painel

Mostrar motivo do bloqueio, respostas anteriores e atuais, versão e histórico. Permitir
solicitar esclarecimentos e concluir a revisão com registro de data e motivo, sem contornar
bloqueios críticos.

Ainda falta: detalhar os critérios que determinam quando uma mudança é relevante. A aprovação
do proprietário no piloto não substitui a estrutura profissional exigida para produção.

## 4. Metodologia de simulação — C04

- Limitar o universo inicial a caixa, Tesouro Selic e CDBs com liquidez diária fictícios.
- Versionar as regras e demonstrar cenários de aprovação e recusa.
- O proprietário define a reserva de cada perfil pelo painel:
  despesas essenciais mensais × quantidade de meses.
- Enquanto a reserva estiver incompleta, a simulação prioriza essa finalidade.
- Se o aporte superar o valor necessário, completar a reserva e manter o excedente em caixa
  simulado até existir outro objetivo aprovado.
- O proprietário aprova os destinos permitidos e sua ordem de prioridade.
- O simulador respeita essa configuração e os bloqueios de elegibilidade e validade dos dados.
- Sem destino elegível, manter o valor em caixa simulado e mostrar o motivo.

## 5. Privacidade e dados — C05 e C07

- Utilizar dados pessoais fictícios até revisar inventário, fornecedores, responsáveis e
  retenção.
- Restringir fontes de mercado às que tenham direitos de uso comprovados.
- Dados vencidos bloqueiam a estratégia afetada.
- Registrar origem e validade dos dados usados nas simulações.

Ainda falta: selecionar fontes, definir validade por tipo de dado e revisar permissões de uso.

## 6. WhatsApp — C06

- Número previsto do bot: +55 75 99260-5555.
- Comercial informado: +1 541 622-1559, sem integração prevista neste momento.
- O número comercial não será necessário para administrar clientes.
- Manter simulação até comprovar as condições para teste real controlado.
- Qualquer teste real dependerá de configuração, elegibilidade, templates e destinatários
  autorizados.

Ainda falta: fechar como o cliente acessará atendimento humano e o prazo de resposta.

## 7. Sequência proposta de execução

| Etapa | Entrega |
| --- | --- |
| 1. Conferir a implementação atual | Identificar o que já existe e quais ajustes são necessários, preservando os controles existentes. |
| 2. Detalhar pendências | Regras de mudança do perfil, condições contratuais, fontes de dados e atendimento humano. |
| 3. Ajustar regras e registros | Perfil, revisão, reserva, destinos, cobrança e renovação, com histórico auditável. |
| 4. Completar o painel e o bot simulado | Configurações do proprietário, fila de revisão e fluxo de desativação da renovação. |
| 5. Ensaiar operação — OPS | Alertas, recuperação, reprocessamento sem duplicação e restauração de backup. |
| 6. Homologar — E2E | Demonstrar a jornada integrada com dados fictícios e apresentar evidências para aceite humano. |

Os testes devem comprovar especialmente os bloqueios de perfil e dados, os cálculos da reserva,
a suspensão após a tolerância e a ausência de renovação ou cobrança após uma solicitação de
desativação.
