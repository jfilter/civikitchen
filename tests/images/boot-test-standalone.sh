#!/bin/bash
# Boot test for the standalone dev image: the real first-boot path against an
# external MariaDB, with the provisioning knobs a developer stack relies on.
# It asserts what the fast (fake-cv) suites under tests/toolbelt cannot:
#   * an extension bind-mounted into the ext dir is enabled without being
#     named in CIVIKITCHEN_ENABLE_EXTENSIONS, its <requires> in place;
#   * CIVIKITCHEN_LOCALES installs the core catalogue and gettext really
#     initialises — ts() renders German, which is the whole point of the knob.
#
# Usage:
#   bash tests/images/boot-test-standalone.sh <image>
#   CK_PROVISION_OVERRIDE=docker/runtime/provision.sh bash tests/images/boot-test-standalone.sh ghcr.io/jfilter/civikitchen:standalone
# CK_PROVISION_OVERRIDE mounts a checkout's provision.sh over the image's —
# for running the test against a published image before it is rebuilt.
set -euo pipefail

IMAGE="${1:?usage: boot-test-standalone.sh <image>}"
FIXTURE="$(cd "$(dirname "$0")/fixtures/ckbootfixture" && pwd)"

RAW="$(echo "${IMAGE}" | tr -c 'a-z0-9' '-')"
SLUG="satest-$(echo "${RAW}" | cut -c1-32)$(echo "${RAW}" | cksum | cut -d' ' -f1)"
NET="${SLUG}-net"
DB="${SLUG}-db"
APP="${SLUG}-app"
HEALTH_TIMEOUT=600   # install + the ~100 MB l10n stream

cleanup() {
    docker rm -f "${APP}" "${DB}" >/dev/null 2>&1 || true
    docker network rm "${NET}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

extra=()
if [ -n "${CK_PROVISION_OVERRIDE:-}" ]; then
    extra+=(-v "$(cd "$(dirname "${CK_PROVISION_OVERRIDE}")" && pwd)/$(basename "${CK_PROVISION_OVERRIDE}"):/usr/local/share/civikitchen/provision.sh:ro")
    echo "==> provision.sh overridden from ${CK_PROVISION_OVERRIDE}"
fi

echo "==> boot-test (standalone) ${IMAGE}"
docker network create "${NET}" >/dev/null
docker run -d --name "${DB}" --network "${NET}" \
    -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=civicrm \
    -e MYSQL_USER=civicrm -e MYSQL_PASSWORD=civicrm mariadb:10.11 >/dev/null
docker run -d --name "${APP}" --network "${NET}" \
    -e CIVICRM_AUTO_INSTALL=1 \
    -e CIVICRM_DB_HOST="${DB}" \
    -e CIVIKITCHEN_SITE_URL=http://localhost \
    -e CIVIKITCHEN_LOCALES=de_DE \
    -e CIVIKITCHEN_DEFAULT_LOCALE=de_DE \
    -v "${FIXTURE}:/var/www/html/ext/ckbootfixture:ro" \
    "${extra[@]}" \
    "${IMAGE}" >/dev/null

echo "==> waiting for healthy (install + provisioning)..."
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
cv() { docker exec -u www-data "${APP}" cv "$@" 2>/dev/null; }

# 1) Site up.
code=$(docker exec "${APP}" curl -s -o /dev/null -w '%{http_code}' -L http://localhost/ 2>/dev/null || echo 000)
check "site serves HTTP 200 (got ${code})" "[ '${code}' = '200' ]"

# 2) The bind-mounted extension is enabled without CIVIKITCHEN_ENABLE_EXTENSIONS.
status=$(cv api4 Extension.get +w key=ckbootfixture +s status | tr -d '[:space:]' || true)
check "mounted extension enabled by the mount alone (${status:-absent})" "echo '${status}' | grep -q '\"installed\"'"

# 3) Core catalogue in place where core reads it, and the default locale set.
l10n=$(cv path -d '[civicrm.l10n]' | tr -d '[:space:]' || true)
check "de_DE catalogue under ${l10n:-?}" \
    "docker exec '${APP}' test -s '${l10n}/de_DE/LC_MESSAGES/civicrm.mo'"
lc=$(cv setting:get lcMessages --out=json-strict | tr -d '[:space:]' || true)
check "lcMessages is de_DE" "echo '${lc}' | grep -q '\"value\":\"de_DE\"'"

# 4) gettext really initialised: without the core .mo this prints "Contacts".
word=$(cv ev 'echo ts("Contacts");' | tr -d '[:space:]"' || true)
check "ts(\"Contacts\") renders German (got '${word}')" "[ '${word}' = 'Kontakte' ]"

if [ "${fail}" = 0 ]; then echo "==> PASS: ${IMAGE}"; else echo "==> FAIL: ${IMAGE}"; exit 1; fi
