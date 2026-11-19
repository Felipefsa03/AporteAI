# Referências oficiais — WhatsApp Cloud API (consulta 19/11/2026)

Este registro fixa as fontes usadas no card WAT. Nenhuma credencial ou dado de
cliente é reproduzido aqui. As URLs oficiais prevalecem sobre resumos locais.

| Tema | Fonte oficial | Uso no transporte |
| --- | --- | --- |
| Componentes do webhook | https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/components | Estrutura `entry[].changes[].value`, `messages` e `statuses`; ACK; lote de atualizações. |
| Criar endpoint de webhook | https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/create-webhook-endpoint.md | GET de verificação responde `200` com `hub.challenge` em texto puro. |
| Enviar mensagens | https://developers.facebook.com/docs/whatsapp/cloud-api/guides/send-messages | `POST /{PHONE_NUMBER_ID}/messages`; `messaging_product=whatsapp`; texto, mídia e template. |
| Mensagens de imagem | https://developers.facebook.com/documentation/business-messaging/whatsapp/messages/image-messages.md | PNG suportado como `image/png`, limite de 5 MB; envio por `id` (upload prévio) ou URL. |
| Códigos de erro | https://developers.facebook.com/documentation/business-messaging/whatsapp/support/error-codes.md | Retry apenas para falhas transitórias; janela expirada e template reprovado são permanentes. |
| Índice para LLMs | https://developers.facebook.com/documentation/business-messaging/whatsapp/llms.txt | Entrada canônica para localização dos documentos acima. |

## Constraints extraídas e implementadas

- O desafio de verificação deve ser devolvido como corpo textual, sem envelope
  JSON e sem HTML.
- O POST assina o **corpo bruto** com HMAC SHA-256 no cabeçalho
  `X-Hub-Signature-256: sha256=<hex>`; re-serializar o JSON invalida a assinatura.
- Um único POST pode conter várias `entry`, várias `changes` e várias mensagens;
  a Meta também reentrega eventos por até 7 dias. Por isso a chave de
  deduplicação é única e o processamento é transacional.
- `statuses` reporta `sent`, `delivered` e `read`; esses eventos são persistidos
  como recibos e atualizam a mensagem de saída correspondente.
- A janela de atendimento é de 24 horas após a última mensagem do destinatário.
  Fora dela, apenas templates aprovados podem iniciar o contato.
- Opt-in é obrigatório para contato proativo; opt-out deve bloquear envios
  posteriores, e a saída para humano aponta para o número comercial manual.
- Códigos transitórios mapeados para retry: `4`, `80007`, `130429`, `131048`,
  `131056`, `131000`, `131016`, `133004`, `2494100`, além de HTTP 429 e 5xx.
- Códigos permanentes mapeados para bloqueio sem retry: `131047` (janela
  expirada), `132001` (template inexistente/não aprovado) e `132015` (template
  pausado).
