#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

for binary in php composer git; do
    if ! command -v "$binary" >/dev/null 2>&1; then
        printf 'ERROR: required binary not found: %s\n' "$binary" >&2
        exit 1
    fi
done

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

if [ -f .env ]; then
    fail "Project .env is not allowed; keep secrets in APORTEAI_RUNTIME_ENV outside the repository."
fi

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
if [ -e "$ENV_FILE" ]; then
    [ -f "$ENV_FILE" ] || fail "Runtime env is not a regular file: $ENV_FILE"
fi

APP_ENV="${APP_ENV:-local}"
APP_DEBUG="${APP_DEBUG:-false}"
DB_CONNECTION="${DB_CONNECTION:-pgsql}"
DB_HOST="${DB_HOST:-/tmp/opencode/aporteai-env-socket}"
DB_PORT="${DB_PORT:-55432}"
DB_DATABASE="${DB_DATABASE:-aporteai}"
DB_USERNAME="${DB_USERNAME:-aporteai}"
DB_PASSWORD="${DB_PASSWORD:-}"
CACHE_STORE="${CACHE_STORE:-array}"
SESSION_DRIVER="${SESSION_DRIVER:-array}"
QUEUE_CONNECTION="${QUEUE_CONNECTION:-database}"
WHATSAPP_QUEUE="${WHATSAPP_QUEUE:-whatsapp}"
PILOT_ENABLED="${PILOT_ENABLED:-false}"
PILOT_PUBLIC_RECOMMENDATIONS="${PILOT_PUBLIC_RECOMMENDATIONS:-false}"
PILOT_ADVISORY_AUTHORIZED="${PILOT_ADVISORY_AUTHORIZED:-false}"
PILOT_OWNER_DASHBOARD_ONLY="${PILOT_OWNER_DASHBOARD_ONLY:-true}"

case "$APP_ENV" in
    local|testing) ;;
    *) fail "APP_ENV=${APP_ENV} is refused; only local and testing are allowed." ;;
esac
[ "$APP_DEBUG" = false ] || fail "APP_DEBUG must be false in local bootstrap."
[ "$DB_CONNECTION" = pgsql ] || fail "DB_CONNECTION must be pgsql."
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
for flag in PILOT_ENABLED PILOT_PUBLIC_RECOMMENDATIONS PILOT_ADVISORY_AUTHORIZED; do
    [ "${!flag}" = false ] || fail "$flag must be false; commercial flags are not enabled by agents."
done
[ "$PILOT_OWNER_DASHBOARD_ONLY" = true ] || fail "PILOT_OWNER_DASHBOARD_ONLY must remain true."

mkdir -p "$(dirname "$ENV_FILE")"
umask 077
if [ ! -e "$ENV_FILE" ]; then
    : > "$ENV_FILE"
fi
[ -f "$ENV_FILE" ] || fail "Runtime env is not a regular file: $ENV_FILE"
chmod 600 "$ENV_FILE"

read_env_key() {
    php -r '
        $file = $argv[1];
        $key = $argv[2];
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (strpos($line, $key."=") === 0) {
                echo substr($line, strlen($key) + 1);
                break;
            }
        }
    ' "$ENV_FILE" "$1"
}

upsert_env() {
    local key="$1" value="$2" tmp
    tmp="$(mktemp "${ENV_FILE}.XXXXXX")"
    grep -v "^${key}=" "$ENV_FILE" > "$tmp" || true
    printf '%s=%s\n' "$key" "$value" >> "$tmp"
    mv "$tmp" "$ENV_FILE"
    chmod 600 "$ENV_FILE"
}

APP_KEY="$(read_env_key APP_KEY)"
if [ -z "$APP_KEY" ]; then
    APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
fi
case "$APP_KEY" in
    base64:*) ;;
    *) fail "APP_KEY is missing or invalid; refusing to continue." ;;
esac
upsert_env APP_KEY "$APP_KEY"

for key in APP_ENV APP_DEBUG DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD CACHE_STORE SESSION_DRIVER QUEUE_CONNECTION WHATSAPP_QUEUE PILOT_ENABLED PILOT_PUBLIC_RECOMMENDATIONS PILOT_ADVISORY_AUTHORIZED PILOT_OWNER_DASHBOARD_ONLY; do
    upsert_env "$key" "${!key}"
done

export APP_KEY APP_ENV APP_DEBUG DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
export CACHE_STORE SESSION_DRIVER QUEUE_CONNECTION WHATSAPP_QUEUE
export PILOT_ENABLED PILOT_PUBLIC_RECOMMENDATIONS PILOT_ADVISORY_AUTHORIZED PILOT_OWNER_DASHBOARD_ONLY

php -r '
$pdo = new PDO(
    "pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=postgres",
    (string) getenv("DB_USERNAME"),
    (string) getenv("DB_PASSWORD")
);
$version = (string) $pdo->query("show server_version")->fetchColumn();
if (strpos($version, "16.") !== 0) {
    fwrite(STDERR, "ERROR: PostgreSQL 16 is required; found ".$version."\n");
    exit(1);
}
$database = (string) getenv("DB_DATABASE");
$exists = $pdo->prepare("select 1 from pg_database where datname = ?");
$exists->execute([$database]);
if (! $exists->fetchColumn()) {
    $pdo->exec("create database \"".$database."\"");
}
'

composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
composer check-platform-reqs
find app bootstrap config database routes tests -type f -name '*.php' -print0 \
    | xargs -0 -n1 php -l

php artisan migrate --force
php artisan db:seed --class=PilotSeeder --force

printf 'Local bootstrap complete. Runtime env: %s (mode 600). See docs/local-environment.md.\n' "$ENV_FILE"
