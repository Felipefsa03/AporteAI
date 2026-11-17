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
