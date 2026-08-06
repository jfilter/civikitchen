#!/usr/bin/env bash
# Wait for the standalone example stack ($COMPOSE_FILE) to auto-install and
# serve its login page; dump the app logs when it never does.
set -euo pipefail

for _ in $(seq 1 60); do
  if curl -fs http://localhost:8080/civicrm/login >/dev/null; then
    echo "ready"
    exit 0
  fi
  sleep 3
done
echo "site never became reachable" >&2
docker compose -f "$COMPOSE_FILE" logs app
exit 1
