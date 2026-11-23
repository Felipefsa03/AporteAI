# Evidência local — painel do proprietário (UI)

Data: 2026-11-23. Branch: `feat/aporteai-owner-panel`.

## Premissas preservadas

- Dados exclusivamente fictícios; `public_recommendations = false`.
- Conta única do proprietário, com MFA opcional no piloto local; clientes não recebem credencial de painel.
- Nenhuma regra de negócio foi duplicada na camada HTML: todas as ações chamam a API administrativa existente.

## Comandos e resultados

```sh
# Sintaxe PHP (todos os diretórios do CI)
find app bootstrap config database routes tests -name '*.php' -print0 | xargs -0 -n1 php -l

# Suíte completa (PostgreSQL descartável, TZ=UTC, memory_limit=512M)
php -d memory_limit=512M vendor/bin/phpunit -c phpunit.local.xml
# OK (148 tests, 956 assertions)
```

A suíte inclui `tests/Feature/OwnerPanelTest.php` com 10 testes / 50 asserções, cobrindo:

- login com e sem MFA, sessão revogada e conta desativada;
- cadastro de cliente pelo painel e auditoria;
- bloqueio de ativação sem opt-in;
- baixa manual de pagamento auditada;
- isolamento entre proprietários;
- métricas SaaS separadas do patrimônio;
- renderização de **todas** as páginas do painel (isso capturou e corrigiu um erro de sintaxe em `billing.blade.php`).

## Navegador

Servidor local: `php artisan serve --host=127.0.0.1 --port=8099`.

- Fluxo verificado no navegador: `/painel/entrar` → dashboard → `/painel/clientes` → `/painel/cobranca` → `/painel/metricas`.
- O viewport disponível no navegador deste ambiente é de **486 px** (`window.matchMedia('(max-width: 640px)').matches === true`), portanto as capturas comprovam a navegação estreita/mobile. A renderização desktop é coberta pelo CSS responsivo e pelos testes de renderização das páginas.
- As mensagens de sessão encerrada/inválida aparecem corretamente na tela de login.

### Capturas

- `ui-login-mobile.png` — tela de acesso exclusivo do proprietário.
- `ui-dashboard-mobile.png` — visão geral do piloto.
- `ui-clients-mobile.png` — lista de clientes fictícios.

## Observação

Não há `gh` nem credencial de publicação neste ambiente, então push/PR não foram realizados. O commit local e esta evidência ficam prontos para revisão e publicação pelo proprietário.
