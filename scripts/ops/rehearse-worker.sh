#!/usr/bin/env bash
set -euo pipefail

: "${ARTISAN:?defina ARTISAN com o caminho para artisan}"
started="$(date +%s)"

php "$ARTISAN" queue:clear database --force >/dev/null 2>&1 || true
php "$ARTISAN" tinker --execute='App\Jobs\DispatchWhatsAppOutbox::dispatch();' >/dev/null

php "$ARTISAN" queue:work database --stop-when-empty --tries=1 --sleep=1 > /tmp/opencode-ops-worker.log 2>&1 &
worker=$!
sleep 2
kill -9 "$worker" 2>/dev/null || true
wait "$worker" 2>/dev/null || true

php "$ARTISAN" queue:work database --stop-when-empty --tries=1 --sleep=1 >> /tmp/opencode-ops-worker.log 2>&1
echo "worker recuperado em $(( $(date +%s) - started ))s"
