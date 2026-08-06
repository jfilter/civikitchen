#!/usr/bin/env bash
# Assert every key in $EXT_KEYS (space-separated) ended up installed in the
# running stack ($COMPOSE_FILE) — covers both CIVIKITCHEN_EXTRA_EXTENSIONS
# syntaxes (bare registry key and key@URL).
set -euo pipefail

for key in $EXT_KEYS; do
  status=$(docker compose -f "$COMPOSE_FILE" exec -T app \
    runuser -u www-data -- cv api4 Extension.get \
    "{\"where\":[[\"key\",\"=\",\"${key}\"]],\"select\":[\"status\"]}" \
    --out=json)
  echo "${key}: ${status}"
  echo "$status" | grep -Eq '"status"[[:space:]]*:[[:space:]]*"installed"' \
    || { echo "FAIL: ${key} not installed" >&2; exit 1; }
done
