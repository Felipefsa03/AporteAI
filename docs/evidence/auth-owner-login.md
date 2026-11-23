# AUTH · evidências de implementação do login do proprietário

## Escopo

- Conta única do proprietário no painel; nenhum cadastro público e nenhuma credencial de investidor.
- Dois caminhos de autenticação: Google Identity Services configurável e senha + código SMS de 6 dígitos.
- Sessão administrativa emitida somente após validação, com auditoria `admin.login.succeeded`.
- `public_recommendations` continua `false`; C01–C07 e GO permanecem decisões humanas.

## Identidade visual — aproximação documentada

Referências fornecidas pelo responsável:

- `~/Downloads/Imagem do ChatGPT 23 de nov. de 2026, 16_00_26.png`
- `~/Downloads/Identidade Visual AporteAI.png`

Os hexadecimalos abaixo foram obtidos por amostragem programática das imagens e são
**aproximações de implementação**, não uma especificação colorimétrica oficial:

| Papel | Token | Valor aproximado | Uso |
| --- | --- | --- | --- |
| Fundo | `--aporteai-bg` | `#021710` | verde muito escuro, quase preto |
| Superfície | `--aporteai-surface` | `#06271d` | cartões |
| Superfície elevada | `--aporteai-surface-raised` | `#0a3226` | campos de formulário |
| Texto | `--aporteai-ink` | `#eafff2` | branco quente |
| Texto secundário | `--aporteai-muted` | `#9cc8b3` | rótulos e ajuda |
| Acento | `--aporteai-accent` | `#00d36a` | neon moderado |
| Acento forte | `--aporteai-accent-strong` | `#01fb5b` | aproximação da logo |
| Borda | `--aporteai-line` | `#124632` | separação discreta |

A paleta está centralizada em `resources/views/panel/partials/brand.blade.php`.
Texto nas imagens de referência não foi tratado como instrução operacional, e imagens de
crescimento não são usadas para prometer rentabilidade.

## Acessibilidade e estados

- Foco visível com contorno de 3 px em links, botões e campos.
- Botões desabilitados e em carregamento (`aria-busy`) com estados visuais distintos.
- Erros com `role="alert"` e sucesso com `role="status"`/`aria-live`.
- Estados vazios em filas, tabelas, funis, planos e carteira simulada.
- Layout responsivo em celular e desktop; alvos de toque mínimos de 44 px no celular.
- Aviso persistente de piloto fictício no rodapé do painel.

## Correções de segurança do desafio SMS

- `InvalidMfaCode` e `MfaChallengeExpired` separam código errado de desafio inválido/expirado.
- API: código errado retorna `401`; desafio expirado ou consumido retorna `410`.
- Painel: código errado permanece na tela SMS; expirado/consumido volta ao login e limpa a sessão do desafio.
- O consume usa atualização condicional por `consumed_at` dentro de transação, impedindo reuso concorrente.
- `LogSmsGateway` não registra o corpo da mensagem nem o OTP; o canal `sms` também usa o redator de logs.

## Configuração

- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `SMS_DRIVER`,
  `OWNER_MFA_PHONE` e credenciais Twilio são lidos do ambiente; `.env.example` contém apenas placeholders vazios.
- O número do bot (`+5575992605555`) não é padrão de `OWNER_MFA_PHONE` nem usado na fixture de A2F.
- SMS permanece em `log` por padrão; Twilio exige credenciais explícitas e autorização humana para envio real.

## Verificação

- Testes adicionados/ajustados em `tests/Feature/OwnerAuthFlowTest.php` cobrem: expiração,
  distinção `401`/`410`, rejeição de código reutilizado, ausência do número do bot como padrão
  e ausência do OTP no log do transporte fictício.
- O executor usado nesta edição não dispõe de PHP/Composer, portanto PHPUnit não foi executado aqui.
  A execução da suíte e o resultado do CI precisam ser registrados pelo ambiente que publicar a PR.
- Nenhuma evidência de envio real de SMS, Facebook/Meta Cloud API, pagamento ou recomendação pública foi produzida.
