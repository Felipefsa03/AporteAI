#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

for binary in php git; do
    if ! command -v "$binary" >/dev/null 2>&1; then
        printf 'ERROR: required binary not found: %s\n' "$binary" >&2
        exit 1
    fi
done

ENV_FILE="${APORTEAI_RUNTIME_ENV:-/tmp/opencode/aporteai-env-runtime.env}"
case "$ENV_FILE" in
    /tmp/opencode/*) ;;
    *) fail "APORTEAI_RUNTIME_ENV must be an absolute path below /tmp/opencode." ;;
esac

ENV_PARENT="$(dirname "$ENV_FILE")"
ENV_NAME="$(basename "$ENV_FILE")"
case "$ENV_NAME" in
    ''|.|..) fail "APORTEAI_RUNTIME_ENV must name a regular file." ;;
esac
[ -d "$ENV_PARENT" ] || fail "Runtime env parent must exist under /tmp/opencode: $ENV_PARENT"
ENV_PARENT_REAL="$(cd "$ENV_PARENT" && pwd -P)"
case "$ENV_PARENT_REAL" in
    /tmp/opencode|/tmp/opencode/*) ;;
    *) fail "Canonical runtime env parent escaped /tmp/opencode: $ENV_PARENT_REAL" ;;
esac
if git -C "$ENV_PARENT_REAL" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    fail "Runtime env must live outside every Git worktree."
fi
ENV_FILE="$ENV_PARENT_REAL/$ENV_NAME"
[ -L "$ENV_FILE" ] && fail "Runtime env must not be a symlink."
[ -f "$ENV_FILE" ] || fail "Runtime env file not found: $ENV_FILE"

while IFS='=' read -r key value; do
    case "$key" in
        APP_ENV|APP_KEY|DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD|CACHE_STORE|SESSION_DRIVER|QUEUE_CONNECTION)
            export "$key=$value"
            ;;
    esac
done < <(php -r '
    foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $line) {
        if ($line === "" || $line[0] === "#" || strpos($line, "=") === false) {
            continue;
        }
        echo $line."\n";
    }
' "$ENV_FILE")

APP_ENV="${APP_ENV:-local}"
DB_CONNECTION="${DB_CONNECTION:-pgsql}"
DB_HOST="${DB_HOST:-/tmp/opencode/aporteai-env-socket}"
DB_PORT="${DB_PORT:-55432}"
DB_DATABASE="${DB_DATABASE:-aporteai}"
CACHE_STORE="${CACHE_STORE:-array}"
SESSION_DRIVER="${SESSION_DRIVER:-array}"
QUEUE_CONNECTION="${QUEUE_CONNECTION:-database}"

case "$APP_ENV" in
    local|testing) ;;
    *) fail "APP_ENV=${APP_ENV} is refused; only local and testing are allowed." ;;
esac
[ "$DB_CONNECTION" = pgsql ] || fail "DB_CONNECTION must be pgsql."
[ "$CACHE_STORE" = array ] || fail "CACHE_STORE must be array for the isolated probe."
[ "$SESSION_DRIVER" = array ] || fail "SESSION_DRIVER must be array for the isolated probe."
[ "$QUEUE_CONNECTION" = database ] || fail "QUEUE_CONNECTION must be database for the isolated probe."
case "$DB_DATABASE" in
    aporteai|aporteai_testing) ;;
    *) fail "DB_DATABASE=${DB_DATABASE} is not an isolated local database." ;;
esac
DB_HOST="${DB_HOST%/}"
case "$DB_HOST" in
    /tmp/opencode/*) ;;
    *) fail "DB_HOST must be a private PostgreSQL socket under /tmp/opencode." ;;
esac
[ -S "$DB_HOST/.s.PGSQL.$DB_PORT" ] || fail "PostgreSQL socket not found at $DB_HOST/.s.PGSQL.$DB_PORT"

php -r '
$pdo = new PDO(
    "pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"),
    (string) getenv("DB_USERNAME"),
    (string) getenv("DB_PASSWORD")
);
$version = (string) $pdo->query("show server_version")->fetchColumn();
if (strpos($version, "16.") !== 0) {
    fwrite(STDERR, "ERROR: PostgreSQL 16 is required; found ".$version."\n");
    exit(1);
}
'

PROBE_QUEUE="probe-$(php -r 'echo bin2hex(random_bytes(8));')"
PROBE_FILE="/tmp/opencode/aporteai-queue-probe-${PROBE_QUEUE}.txt"
[ ! -e "$PROBE_FILE" ] || fail "Probe marker already exists; refusing to overwrite."

export APORTEAI_QUEUE_PROBE_FILE="$PROBE_FILE"
export APORTEAI_PROBE_QUEUE="$PROBE_QUEUE"
umask 077

PROBE_DIR="$(mktemp -d /tmp/opencode/aporteai-queue-dispatch-XXXXXX)"
trap 'rm -rf "$PROBE_DIR"' EXIT
DISPATCH_FILE="$PROBE_DIR/dispatch.php"
cat > "$DISPATCH_FILE" <<'PHP'
<?php

declare(strict_types=1);

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

dispatch(function (): void {
    $path = (string) getenv('APORTEAI_QUEUE_PROBE_FILE');
    file_put_contents($path, "queue-ok\n", FILE_APPEND | LOCK_EX);
})->onQueue((string) getenv('APORTEAI_PROBE_QUEUE'));
PHP
chmod 600 "$DISPATCH_FILE"

php "$DISPATCH_FILE"

php artisan queue:work --queue="$PROBE_QUEUE" --once --stop-when-empty

[ -s "$PROBE_FILE" ] || fail "Queue probe did not produce a marker"
grep -qx 'queue-ok' "$PROBE_FILE" || fail "Unexpected queue probe marker"

printf 'Local queue probe passed on isolated queue %s; marker %s.\n' "$PROBE_QUEUE" "$PROBE_FILE"
