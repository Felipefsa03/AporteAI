#!/usr/bin/env bash
set -euo pipefail

: "${OPS_BASE_URL:?defina OPS_BASE_URL}"
: "${OPS_TOKEN:?defina OPS_TOKEN fora do repositório}"

samples="${OPS_SAMPLES:-20}"
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

for _ in $(seq 1 "$samples"); do
  curl -sS -o /dev/null -w '%{time_total}\n' \
    -H "X-Ops-Token: $OPS_TOKEN" "${OPS_BASE_URL}/ops/health" >> "$tmp"
done

sort -n "$tmp" | awk -v n="$samples" '
  { values[NR] = $1 }
  END {
    p50 = values[int((n - 1) * 0.50) + 1]
    p95 = values[int((n - 1) * 0.95) + 1]
    printf "samples=%d p50_ms=%.0f p95_ms=%.0f max_ms=%.0f\n", n, p50*1000, p95*1000, values[n]*1000
  }'
