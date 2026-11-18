# AporteAI

Workspace do MVP AporteAI.

## API do proprietário

O dashboard é exclusivo do proprietário: não existe credencial web para investidores.
O acesso exige senha + TOTP (RFC 6238) e emite uma sessão de 8 horas. As rotas ficam
sob `/api/v1/admin` e exigem o cabeçalho `Authorization: Bearer <token>` após o login.

### Execução local

Consulte o [ambiente local reproduzível](docs/local-environment.md). Em um checkout
limpo com PHP 8.3+, Composer 2 e PostgreSQL 16 disponíveis:

```bash
./scripts/bootstrap-local.sh
```

O script valida o ambiente, instala o lockfile, aplica as migrações e executa o
`PilotSeeder`, que pode ser repetido sem duplicar dados fictícios. Nenhum `.env`
é criado no projeto: a `APP_KEY` fica em `/tmp/opencode/aporteai-env-runtime.env`,
modo `600`, fora de worktrees. Para verificar a fila sem tocar `whatsapp` e rodar
os testes:

```bash
./scripts/verify-local-queue.sh
DB_DATABASE=aporteai_testing vendor/bin/phpunit --filter PilotSeederTest
```

Nenhum segredo, provedor externo ou credencial de cliente é necessário.

### Delimitação

- `app/Domain/AdminAuth`: TOTP, login e revogação de sessão.
- `app/Domain/ClientCRM`: cadastro manual de investidores e trilha de auditoria.
- `app/Http/Middleware/EnsureAdminMfa`: barreira que impede acesso sem MFA.
- `database/migrations`: esquema PostgreSQL (proprietário, sessões, investidores, vendas, auditoria).
- `.github/workflows/ci.yml`: PHP 8.3 + PostgreSQL 16 + PHPUnit.

Nenhum registro financeiro é exposto por investidor cruzado: o cadastro é feito pelo
proprietário e todas as mutações geram `audit_events` com `trace_id`.

## Data Hub

`php artisan market:sync` ingere BCB, Tesouro, CVM e o provedor comercial conforme
`config/market_data.php`. Cada fonte declara licença, direito de uso e janela de
atualidade; cotações vencidas ficam marcadas como `stale` e registros inválidos são
rejeitados — nenhum preço é inventado.

## Cobrança

`config/billing.php` define o produto único: R$ 29,90/mês ou 12 × R$ 19,90
(R$ 238,80/ano), com as mesmas funcionalidades. Valores são inteiros em centavos.
A assinatura registra separadamente `status` (cobrança) e `access_status` (acesso):
vencimento sem quitação conciliada suspende o acesso sem cancelar o contrato anual.
Webhooks são idempotentes por `external_id` e `processing` nunca é tratado como quitação.

## Integração contínua

`composer test` executa PHPUnit. O workflow roda Unit e Feature em PHP 8.3 e
PostgreSQL 16 e preserva JUnit mesmo em falhas. Consulte
[verificação real e bloqueios conhecidos](docs/ci-verification.md). CI vermelho
não representa homologação técnica nem autorização comercial.
