# CI e verificação real — 17/11/2026

Base auditada: `2c5673be4f87841fc65dc6d6448b91d6bb5c0224`.
Gartrol: card `e328f540-1fd0-4bf3-9fc7-43620803d5be`, missão S10.

## Execução

O projeto não continha lockfile nem workflow. O script `php artisan test` não
existia nas dependências declaradas, e os 55 testes funcionais terminavam com
`Class "Mockery" not found`, sem assertions. O PR adiciona Mockery, usa PHPUnit
diretamente em `composer test`, fixa dependências compatíveis com PHP 8.3 e
versiona os diretórios de runtime necessários a um checkout limpo.

O workflow roda em pull requests, main e disparo manual, com permissão de leitura,
ações fixadas por SHA e PostgreSQL 16 efêmero. Não usa segredos de produção nem
faz deploy. Resultados JUnit são preservados mesmo quando há falhas; nenhum teste
é ignorado nem tem falha tolerada. Recomendações e piloto permanecem desabilitados.

Verificação local: PHP 8.4.27, PostgreSQL 16, PHPUnit 11.5.58. Dependências
resolvidas com plataforma Composer PHP 8.3.0; execução em PHP 8.3 é responsabilidade
do workflow. Banco descartável sem rede, acessado por socket local.

```sh
composer install --no-interaction --prefer-dist
composer validate --strict
composer check-platform-reqs
composer test -- --log-junit build/full.xml
```

O banco `aporteai_testing` é exclusivo de testes: RefreshDatabase recria tabelas.
Configure DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD externamente, conforme phpunit.xml.
Não use banco de produção.

## Resultado e bloqueios

- Validação estrita do Composer e requisitos da plataforma: aprovados.
- Sintaxe: 163 arquivos PHP aprovados na base auditada.
- Unit: 16 testes / 31 assertions aprovados; provider migrado para atributo PHPUnit.
- Feature após corrigir preparação: 55 testes / 76 assertions, 36 erros e 5 falhas.
- A suíte permanece vermelha. Isso não é homologação P0 nem prontidão comercial.

Falhas observadas que exigem correção funcional e revisão própria:

1. `audit_events.actor_id` é UUID, mas serviços gravam nomes como billing-service,
   compliance-gate, allocation-engine, market-data-ingestor e portfolio-ledger.
2. Fixtures conflitam com a conta administrativa ativa única; outros cenários
   tentam inserir MFA nulo numa coluna não nula ou obtêm investidor inexistente.
3. SubscriptionService recebe access_status nulo imediatamente após criar modelo,
   antes de carregar defaults persistidos pelo banco.
4. Mensagens/comandos de bloqueio financeiro divergem das expectativas dos testes.
5. Seeder tenta inserir activation_requests sem template_name obrigatório.
6. Persistência de PNG envia bytes binários incompatíveis com a codificação UTF-8.

Não relaxar a conta única, MFA, gates financeiros ou compliance para deixar o CI verde.
Corrigir cada contrato e retestar com a evidência original preservada. Revisão humana
é necessária para alterações de segurança, cobrança ou persistência financeira.

## Auditoria Git e liberação

`fetch --prune` concluído; main local e origin/main iguais à base acima.
GitHub confirmou PRs #1–#18 merged. Todas as branches locais anteriores eram
ancestrais de main. Cinco branches remotas têm merges intermediários fora do
histórico de main, porém `git cherry` e diff da base comum retornaram vazios:
adm-dashboard, dad-data-hub, fat-billing, bot-whatsapp, billing-gate. Nenhuma
branch ou tag foi removida; as cinco tags backup foram preservadas.

C01–C07 e GO estavam abertos no Gartrol. `pilot.public_recommendations=false` e
`compliance.advisory_authorized` tem default false. O ambiente de produção não foi
auditado; uma variável de ambiente pode substituir o segundo valor. Nenhuma
liberação comercial, validação humana ou transferência de repositório foi feita.

## Adendo ENV 18/11/2026

As falhas acima são históricas da base `2c5673b`. Após as entregas REG e BASE,
integradas em `main` no commit `f1c8290`, a suíte completa em PHP 8.4/PostgreSQL
16.10 passou com 71 testes e 328 assertions; o workflow em PHP 8.3/PG16 também
ficou verde no merge do PR #21 (run `44379927893`). Este adendo não reatribui
autoria nem apaga a evidência original.

A reprodução local foi formalizada em [ambiente local](local-environment.md):
checkout limpo sem `.env`, chave em arquivo externo modo `600`, PostgreSQL 16 por
socket privado, seeder repetível e sonda de fila isolada. Nenhuma dessas mudanças
altera gates humanos, recomendações públicas ou autorização comercial.
