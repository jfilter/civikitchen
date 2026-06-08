#!/bin/bash
# Boot test for the single-container DEMO images (civikitchen:*-demo,
# civicrm-eu-ngo). Unlike images/test/boot-test.sh (which spins up an external
# MariaDB sidecar), the demo image is self-contained: `docker run` it, wait for
# the HEALTHCHECK, and assert the baked site + embedded DB come up. This guards
# the build-time bake of the embedded DB and the demo entrypoint.
#
# Usage:
#   bash images/test/boot-test-demo.sh <image>
#   bash images/test/boot-test-demo.sh civikitchen:drupal10-demo
#   bash images/test/boot-test-demo.sh civicrm-eu-ngo:latest
set -euo pipefail

IMAGE="${1:?usage: boot-test-demo.sh <image>}"

SLUG="demotest-$(echo "${IMAGE}" | tr -c 'a-z0-9' '-')"
APP="${SLUG}-app"
HEALTH_TIMEOUT=300   # baked site → first boot is fast; allow slack

cleanup() { docker rm -f "${APP}" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "==> boot-test (demo) ${IMAGE}"
docker rm -f "${APP}" >/dev/null 2>&1 || true
docker run -d --name "${APP}" "${IMAGE}" >/dev/null

echo "==> waiting for healthy (embedded MariaDB + provisioning)..."
elapsed=0
while :; do
    health=$(docker inspect -f '{{.State.Health.Status}}' "${APP}" 2>/dev/null || echo gone)
    state=$(docker inspect -f '{{.State.Status}}' "${APP}" 2>/dev/null || echo gone)
    [ "${health}" = "healthy" ] && { echo "    healthy after ~${elapsed}s"; break; }
    if [ "${state}" = "exited" ] || [ "${state}" = "gone" ]; then
        echo "!! container ${state} before healthy — last logs:"; docker logs --tail 40 "${APP}" 2>&1 || true
        exit 1
    fi
    if [ "${elapsed}" -ge "${HEALTH_TIMEOUT}" ]; then
        echo "!! not healthy within ${HEALTH_TIMEOUT}s — last logs:"; docker logs --tail 40 "${APP}" 2>&1 || true
        exit 1
    fi
    sleep 5; elapsed=$((elapsed + 5))
done

fail=0
check() { if eval "$2"; then echo "  ✓ $1"; else echo "  ✗ $1"; fail=1; fi; }

# 1) The site serves 200 (follow the standalone bare-/ -> /civicrm/login redirect).
code=$(docker exec "${APP}" curl -s -o /dev/null -w '%{http_code}' -L http://localhost/ 2>/dev/null || echo 000)
check "site serves HTTP 200 (got ${code})" "[ '${code}' = '200' ]"

# 2) CiviCRM is live against the embedded DB.
ver=$(docker exec -u buildkit -w /home/buildkit/buildkit/build/site/web "${APP}" \
    bash -lc 'export PATH=/home/buildkit/buildkit/bin:$PATH; cv api4 Domain.get +s version 2>/dev/null' \
    | tr -d '[:space:]' || true)
check "CiviCRM responds via cv (Domain version: ${ver:-none})" "echo '${ver}' | grep -q 'version'"

# 3) Demo data is baked in (the -demo civibuild types seed sample contacts).
contacts=$(docker exec -u buildkit -w /home/buildkit/buildkit/build/site/web "${APP}" \
    bash -lc 'export PATH=/home/buildkit/buildkit/bin:$PATH; cv api4 Contact.get +s id 2>/dev/null' \
    | grep -c '"id"' || true)
check "demo data present (${contacts} contacts)" "[ '${contacts:-0}' -gt 1 ]"

# 4) Embedded DB started clean from the baked data dir (no InnoDB crash recovery).
if docker logs "${APP}" 2>&1 | grep -qiE 'InnoDB.*(crash recovery|Starting crash recovery|Recovering)'; then
    echo "  ✗ embedded MariaDB did InnoDB crash recovery (baked data dir not cleanly shut down)"; fail=1
else
    echo "  ✓ embedded MariaDB started clean (no InnoDB recovery)"
fi

[ "${fail}" = 0 ] && echo "==> PASS: ${IMAGE}" || { echo "==> FAIL: ${IMAGE}"; exit 1; }
