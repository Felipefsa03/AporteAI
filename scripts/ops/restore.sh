#!/usr/bin/env bash
set -euo pipefail

: "${BACKUP_FILE:?defina BACKUP_FILE}"
: "${OPS_BACKUP_PASSPHRASE:?defina OPS_BACKUP_PASSPHRASE fora do repositório}"
: "${PGDATABASE:?defina PGDATABASE}"
: "${OPS_CONFIRM_RESTORE:?defina OPS_CONFIRM_RESTORE=1 para confirmar o banco descartável}"

command -v pg_restore >/dev/null || { echo "pg_restore indisponível" >&2; exit 1; }
command -v gpg >/dev/null || { echo "gpg indisponível" >&2; exit 1; }

plain="$(mktemp)"
trap 'rm -f "$plain"' EXIT

gpg --batch --yes --decrypt --passphrase "$OPS_BACKUP_PASSPHRASE" \
  --output "$plain" "$BACKUP_FILE"
started="$(date +%s)"
pg_restore --clean --if-exists --no-owner --no-privileges --exit-on-error \
  --dbname "$PGDATABASE" "$plain"
echo "restore concluído em $(( $(date +%s) - started ))s no banco ${PGDATABASE}"
