#!/usr/bin/env bash
# Wait for the app service in $COMPOSE_FILE to report a healthy healthcheck;
# dump its logs when it exits, turns unhealthy, or never gets there.
set -euo pipefail

for _ in $(seq 1 120); do
  cid=$(docker compose -f "$COMPOSE_FILE" ps -q app)
  health=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$cid" 2>/dev/null || echo starting)
  if [ "$health" = healthy ]; then
    echo "ready"
    exit 0
  fi
  if [ "$health" = exited ] || [ "$health" = unhealthy ]; then
    echo "container became ${health}" >&2
    docker compose -f "$COMPOSE_FILE" logs app
    exit 1
  fi
  sleep 5
done
echo "site never became healthy" >&2
docker compose -f "$COMPOSE_FILE" logs app
exit 1
