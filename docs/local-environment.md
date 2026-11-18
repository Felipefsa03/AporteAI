# Ambiente local reproduzível — 18/11/2026

Este adendo descreve como executar o AporteAI em um checkout limpo sem segredos no
repositório. Ele atualiza no tempo as instruções antigas de `README.md`,
`docs/plano-execucao-mvp.md` e `docs/ci-verification.md` sem apagar o histórico.

## Regra de segredos

Nenhum `.env` é criado, lido ou executado pelo bootstrap dentro do projeto. A
chave da aplicação e as variáveis locais ficam em um arquivo externo, por padrão:

```text
/tmp/opencode/aporteai-env-runtime.env
```

Antes de qualquer escrita, o caminho é canonicalizado e aceito somente se for:

- absoluto e abaixo de `/tmp/opencode`;
- regular, sem symlink;
- fora de qualquer worktree Git.

O conteúdo nunca é avaliado como shell: o bootstrap lê apenas a chave `APP_KEY`
com um parser PHP e exporta variáveis explicitamente. A chave é gerada por
`random_bytes`/`base64` em PHP puro, sem depender de `vendor/`, e é gravada somente
nesse arquivo com modo `600`. A segunda execução reaproveita a mesma chave sem
exibi-la. O caminho pode ser trocado com `APORTEAI_RUNTIME_ENV`, desde que
permaneça dentro das restrições acima.

## Validações obrigatórias

O bootstrap recusa explicitamente:

- `APP_ENV` fora de `local` ou `testing`;
- `APP_DEBUG=true`;
- `DB_CONNECTION` diferente de `pgsql`;
- `DB_DATABASE` diferente de `aporteai` ou `aporteai_testing`;
- `DB_HOST` que não seja um diretório de socket sob `/tmp/opencode`;
- `CACHE_STORE` ou `SESSION_DRIVER` diferentes de `array` na sonda de fila;
- socket PostgreSQL 16 ausente ou versão diferente de 16;
- `PILOT_ENABLED`, `PILOT_PUBLIC_RECOMMENDATIONS` ou `PILOT_ADVISORY_AUTHORIZED`
  diferentes de `false`;
- `PILOT_OWNER_DASHBOARD_ONLY` diferente de `true`;
- presença de `.env` no repositório.

Nenhuma migração é aplicada a banco arbitrário.

## Pré-requisitos

- PHP 8.3 ou superior com as extensões do `composer.lock`.
- Composer 2.
- PostgreSQL 16 isolado, exclusivo de desenvolvimento/teste. Nunca use banco de
  produção, staging ou homologação.
- Nenhuma credencial de WhatsApp, pagamento, LLM ou e-mail é necessária.

## PostgreSQL 16 por socket privado

Use uma instância descartável com socket em `/tmp/opencode`, sem escuta de rede.
Quando os binários locais estiverem disponíveis e a instância ainda não existir:

```sh
PG_BIN="$HOME/.local/opt/postgres/bin"
DATA_DIR=/tmp/opencode/aporteai-env-pg
SOCKET_DIR=/tmp/opencode/aporteai-env-socket
LOG=/tmp/opencode/aporteai-env-postgres.log

mkdir -p "$SOCKET_DIR"
"$PG_BIN/initdb" -D "$DATA_DIR" -U aporteai --auth=trust
"$PG_BIN/pg_ctl" -D "$DATA_DIR" -l "$LOG" \
  -o "-k $SOCKET_DIR -p 55432 -c listen_addresses=''" start
```

Não reinicie `initdb` nem `pg_ctl start` se o socket
`/tmp/opencode/aporteai-env-socket/.s.PGSQL.55432` já existir.

## Bootstrap em checkout limpo

```sh
./scripts/bootstrap-local.sh
./scripts/bootstrap-local.sh
```

A primeira execução instala o lockfile, valida o Composer, verifica a plataforma,
faz lint, cria o banco local permitido se necessário, aplica as migrações
(incluindo `jobs` e `failed_jobs`) e executa o `PilotSeeder`. A segunda execução
reaproveita a chave e não duplica carteiras, eventos, sessões de aporte nem casos
de revisão.

Equivalente manual, após exportar as variáveis do arquivo externo sem avaliá-lo
como shell:

```sh
composer install --no-interaction --prefer-dist --no-progress
php artisan migrate --force
php artisan db:seed --class=PilotSeeder --force
```

## Worker isolado sem rede

O job de verificação usa uma fila aleatória `probe-*`, nunca `whatsapp`, e grava
apenas um marcador em `/tmp/opencode`. O worker consome exclusivamente essa fila,
uma única vez; o código do job não contém chamada de rede:

```sh
./scripts/verify-local-queue.sh
```

Para operar a fila real manualmente:

```sh
php artisan queue:work --queue="$WHATSAPP_QUEUE" --tries=3
```

Não configure gateway de WhatsApp real nem aponte o worker para número de cliente.

## Comandos de teste

Verificação rápida do seeder repetível:

```sh
DB_DATABASE=aporteai_testing vendor/bin/phpunit --filter PilotSeederTest
```

Gate completo antes de integrar:

```sh
composer validate --strict
composer check-platform-reqs
find app bootstrap config database routes tests -type f -name '*.php' -print0 \
  | xargs -0 -n1 php -l
DB_DATABASE=aporteai_testing composer test -- --log-junit /tmp/aporteai-junit.xml
```

O JUnit fica fora do repositório. `RefreshDatabase` recria o esquema de teste;
nunca execute a suíte contra banco compartilhado. Em 18/11/2026, no commit
`f1c8290`, a suíte base registrou 71 testes e 328 assertions aprovados em
PostgreSQL 16.10.

## Limites

`config/pilot.php` mantém `public_recommendations=false`, `advisory_authorized=false`
e `owner_dashboard_only=true`. C01–C07 e GO permanecem sob decisão humana. Este
bootstrap não faz chamadas externas, não envia mensagens, não executa ordens e não
autoriza operação comercial.
