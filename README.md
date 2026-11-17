# AporteAI

Workspace do MVP AporteAI.

## API do proprietário

O dashboard é exclusivo do proprietário: não existe credencial web para investidores.
O acesso exige senha + TOTP (RFC 6238) e emite uma sessão de 8 horas. As rotas ficam
sob `/api/v1/admin` e exigem o cabeçalho `Authorization: Bearer <token>` após o login.

### Execução local

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test
```

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
