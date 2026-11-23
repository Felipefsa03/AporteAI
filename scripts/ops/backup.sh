#!/usr/bin/env bash
set -euo pipefail

: "${BACKUP_DIR:?defina BACKUP_DIR}"
: "${OPS_BACKUP_PASSPHRASE:?defina OPS_BACKUP_PASSPHRASE fora do repositório}"
: "${PGDATABASE:?defina PGDATABASE}"

command -v pg_dump >/dev/null || { echo "pg_dump indisponível" >&2; exit 1; }
command -v gpg >/dev/null || { echo "gpg indisponível" >&2; exit 1; }

umask 077
mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
plain="$(mktemp)"
cipher="${BACKUP_DIR}/aporteai-${PGDATABASE}-${stamp}.dump.gpg"
trap 'rm -f "$plain"' EXIT

pg_dump --format=custom --no-owner --no-privileges "$PGDATABASE" > "$plain"
gpg --batch --yes --symmetric --cipher-algo AES256 \
  --passphrase "$OPS_BACKUP_PASSPHRASE" --output "$cipher" "$plain"
sha256sum "$cipher" > "${cipher}.sha256"

echo "backup criptografado: ${cipher}"
