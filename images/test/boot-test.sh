#!/bin/bash
# Boot test for the buildkit-backed CMS dev images.
#
# Unlike test-dev-tools.sh (which runs WITH the entrypoint overridden and only
# checks the bundled tools), this exercises the real first-boot path end to end:
# start an external MariaDB, boot the image against it, and assert the baked
# site comes up. It guards the bake → runtime `civibuild reinstall` flow — in
# particular that the build-time DB host (127.0.0.1) gets repointed at the
# external sidecar (regression: "[2002] Connection refused" on first boot).
#
# Usage:
#   bash images/test/boot-test.sh <image> <site_type>
#   bash images/test/boot-test.sh civikitchen:drupal10  drupal10-demo
#   bash images/test/boot-test.sh civikitchen:drupal11  drupal11-demo
#   bash images/test/boot-test.sh civikitchen:wordpress wp-demo
#   bash images/test/boot-test.sh civikitchen:joomla    joomla-demo
set -euo pipefail

IMAGE="${1:?usage: boot-test.sh <image> <site_type>}"
SITE_TYPE="${2:?usage: boot-test.sh <image> <site_type>}"

# Unique names so parallel CI matrix legs / local runs don't collide. The DB
# container name doubles as its DNS hostname on the test network, and DNS
# labels are capped at 63 chars — CI image refs carry a 40-char sha tag, so
# keep a short readable prefix and a checksum for uniqueness.
RAW="$(echo "${IMAGE}_${SITE_TYPE}" | tr -c 'a-z0-9' '-')"
SLUG="boottest-$(echo "${RAW}" | cut -c1-32)$(echo "${RAW}" | cksum | cut -d' ' -f1)"
NET="${SLUG}-net"
DB="${SLUG}-db"
APP="${SLUG}-app"
HEALTH_TIMEOUT=600   # seconds; fast reinstall path is ~60s, allow slack

cleanup() {
    docker rm -f "${APP}" "${DB}" >/dev/null 2>&1 || true
    docker network rm "${NET}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> boot-test ${IMAGE} (${SITE_TYPE})"
docker network create "${NET}" >/dev/null
docker run -d --name "${DB}" --network "${NET}" \
    -e MYSQL_ROOT_PASSWORD=root mariadb:10.11 >/dev/null
docker run -d --name "${APP}" --network "${NET}" \
    -e CIVICRM_DB_HOST="${DB}" \
    -e CIVICRM_DB_ROOT_PASSWORD=root \
    -e CIVIKITCHEN_SITE_URL=http://localhost \
    -e CIVICRM_SITE_TYPE="${SITE_TYPE}" \
    "${IMAGE}" >/dev/null

echo "==> waiting for healthy (first-boot reinstall + provisioning)..."
elapsed=0
while :; do
    health=$(docker inspect -f '{{.State.Health.Status}}' "${APP}" 2>/dev/null || echo gone)
    state=$(docker inspect -f '{{.State.Status}}' "${APP}" 2>/dev/null || echo gone)
    [ "${health}" = "healthy" ] && { echo "    healthy after ~${elapsed}s"; break; }
    if [ "${state}" = "exited" ] || [ "${state}" = "gone" ]; then
        echo "!! container ${state} before becoming healthy — last logs:"
        docker logs --tail 40 "${APP}" 2>&1 || true
        exit 1
    fi
    if [ "${elapsed}" -ge "${HEALTH_TIMEOUT}" ]; then
        echo "!! not healthy within ${HEALTH_TIMEOUT}s — last logs:"
        docker logs --tail 40 "${APP}" 2>&1 || true
        exit 1
    fi
    sleep 5; elapsed=$((elapsed + 5))
done

fail=0
check() { if eval "$2"; then echo "  ✓ $1"; else echo "  ✗ $1"; fail=1; fi; }

# 1) HTTP: the CMS home page serves 200 (checked inside the container, no host port).
code=$(docker exec "${APP}" curl -s -o /dev/null -w '%{http_code}' -L http://localhost/ 2>/dev/null || echo 000)
check "home page serves HTTP 200 (got ${code})" "[ '${code}' = '200' ]"

# 2) CiviCRM is live against the EXTERNAL db (the thing the host-rewrite fixes).
# cv works on every flavor including Joomla now — the buildkit images ship a
# civicrm.settings.d bootstrap shim (joomla-cli-bootstrap.php) that loads
# Joomla's framework for cv — so no DB-direct fallback is needed.
ver=$(docker exec -u buildkit -w /home/buildkit/buildkit/build/site/web "${APP}" \
    bash -lc 'export PATH=/home/buildkit/buildkit/bin:$PATH; cv api4 Domain.get +s version 2>/dev/null' \
    | tr -d '[:space:]' || true)
check "CiviCRM responds via cv (Domain version: ${ver:-none})" "echo '${ver}' | grep -q 'version'"

# 3) Joomla only: the option=com_civicrm route must be registered. cv (check 2)
# boots via the settings.d shim regardless of Joomla's component registration,
# so it CANNOT catch a half-finished install — but that registration is exactly
# what the dev image's first-boot finish restores (joomla-finish.sh, re-run after
# `civibuild reinstall` wipes civibuild's incomplete state) and what the admin UI
# and api_key HTTP API depend on. An unregistered component makes Joomla 404 the
# route; a registered one routes into CiviCRM (200/redirect/permission, never a
# Joomla 404). This is the regression guard for the dev :joomla finish.
#
# Gate on the SITE_TYPE arg, NOT a cv-derived UF: this must fail-closed — the
# guard has to run on the Joomla image even if cv itself can't boot (that case
# is already caught by check 2). It is the same flavor signal entrypoint.sh and
# bake.sh gate the finish on.
if [[ "${SITE_TYPE}" == joomla* ]]; then
    jcode=$(docker exec "${APP}" curl -s -o /dev/null -w '%{http_code}' \
        'http://localhost/index.php?option=com_civicrm' 2>/dev/null || echo 000)
    check "Joomla com_civicrm route is registered (HTTP ${jcode}, not 404)" \
        "[ '${jcode}' != '404' ] && [ '${jcode}' != '000' ]"
fi

if [ "${fail}" = 0 ]; then echo "==> PASS: ${IMAGE}"; else echo "==> FAIL: ${IMAGE}"; exit 1; fi
