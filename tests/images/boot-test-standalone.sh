#!/bin/bash
# Boot test for the standalone dev image: the real first-boot path against an
# an external MySQL-compatible database, with the provisioning knobs a
# developer stack relies on.
# It asserts what the fast (fake-cv) suites under tests/toolbelt cannot:
#   * an extension bind-mounted into the ext dir is enabled without being
#     named in CIVIKITCHEN_ENABLE_EXTENSIONS, its <requires> in place;
#   * CIVIKITCHEN_LOCALES installs the core catalogue and gettext really
#     initialises — ts() renders German, which is the whole point of the knob;
#   * an external profile is schema-validated and applied, with generated API
#     credentials written mode 0600 and never disclosed in default logs.
#
# Usage:
#   bash tests/images/boot-test-standalone.sh <image>
#   CK_PROVISION_OVERRIDE=docker/runtime/provision.sh bash tests/images/boot-test-standalone.sh ghcr.io/jfilter/civikitchen:standalone
#   CK_DATABASE_IMAGE=mysql:8.0 bash tests/images/boot-test-standalone.sh ghcr.io/jfilter/civikitchen:standalone
# CK_PROVISION_OVERRIDE mounts a checkout's provision.sh over the image's —
# for running the test against a published image before it is rebuilt.
set -euo pipefail

IMAGE="${1:?usage: boot-test-standalone.sh <image>}"
DATABASE_IMAGE="${CK_DATABASE_IMAGE:-mariadb:10.11}"
FIXTURE="$(cd "$(dirname "$0")/fixtures/ckbootfixture" && pwd)"
PROFILE_FIXTURE="$(cd "$(dirname "$0")/fixtures/external-profile" && pwd)"
DB_INIT="$(cd "$(dirname "$0")/../../examples/standalone/db-init" && pwd)/01-grants.sql"

RAW="$(echo "${IMAGE}-${DATABASE_IMAGE}" | tr -c 'a-z0-9' '-')"
SLUG="satest-$(echo "${RAW}" | cut -c1-32)$(echo "${RAW}" | cksum | cut -d' ' -f1)"
NET="${SLUG}-net"
DB="${SLUG}-db"
APP="${SLUG}-app"
HEALTH_TIMEOUT=600   # install + the ~100 MB l10n stream

cleanup() {
    docker rm -fv "${APP}" "${DB}" >/dev/null 2>&1 || true
    docker network rm "${NET}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

extra=()
if [ -n "${CK_PROVISION_OVERRIDE:-}" ]; then
    extra+=(-v "$(cd "$(dirname "${CK_PROVISION_OVERRIDE}")" && pwd)/$(basename "${CK_PROVISION_OVERRIDE}"):/usr/local/share/civikitchen/provision.sh:ro")
    echo "==> provision.sh overridden from ${CK_PROVISION_OVERRIDE}"
fi

echo "==> boot-test (standalone) ${IMAGE} database=${DATABASE_IMAGE}"
docker network create "${NET}" >/dev/null
docker run -d --name "${DB}" --network "${NET}" \
    -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=civicrm \
    -e MYSQL_USER=civicrm -e MYSQL_PASSWORD=civicrm \
    -v "${DB_INIT}:/docker-entrypoint-initdb.d/01-grants.sql:ro" \
    "${DATABASE_IMAGE}" >/dev/null
docker run -d --name "${APP}" --network "${NET}" \
    -e CIVICRM_AUTO_INSTALL=1 \
    -e CIVICRM_DB_HOST="${DB}" \
    -e CIVIKITCHEN_SITE_URL=http://localhost \
    -e CIVIKITCHEN_LOCALES=de_DE \
    -e CIVIKITCHEN_DEFAULT_LOCALE=de_DE \
    -e CIVIKITCHEN_DEMO_USER=admin \
    -e CIVIKITCHEN_PROFILE=minimal \
    -e CIVIKITCHEN_PROFILE_PATH=/civikitchen-external-profiles \
    -e CIVIKITCHEN_TRUST_EXTERNAL_PROFILES=1 \
    -v "${FIXTURE}:/var/www/html/ext/ckbootfixture:ro" \
    -v "${PROFILE_FIXTURE}:/civikitchen-external-profiles:ro" \
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

db_version=$(docker exec "${DB}" sh -c 'mariadb -uroot -proot -Nse "SELECT CONCAT(@@version, \" \", @@version_comment)" 2>/dev/null || mysql -uroot -proot -Nse "SELECT CONCAT(@@version, \" \", @@version_comment)"' 2>/dev/null || true)
check "database responds (${db_version:-unknown})" "[ -n '${db_version}' ]"

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

# 5) The compatibility leg includes the scratch DB contract, not only install.
# A green site with an empty civicrm_test DB would still wipe developer data on
# the first headless suite, so boot CiviCRM through UnitTests and read from it.
test_db=$(docker exec -e CIVICRM_UF=UnitTests -u www-data "${APP}" \
    cv ev 'CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS ck_test_db_canary (id INT PRIMARY KEY)"); echo CRM_Core_DAO::singleValueQuery("SELECT DATABASE()");' \
    2>/dev/null | tr -d '[:space:]"' || true)
check "UnitTests process is connected to civicrm_test (got ${test_db:-absent})" "[ '${test_db}' = 'civicrm_test' ]"
main_canary=$(docker exec "${DB}" sh -c 'mariadb -uroot -proot civicrm -Nse "SHOW TABLES LIKE '\''ck_test_db_canary'\''" 2>/dev/null || mysql -uroot -proot civicrm -Nse "SHOW TABLES LIKE '\''ck_test_db_canary'\''"' 2>/dev/null || true)
check "test DB canary is absent from the main civicrm database" "[ -z '${main_canary}' ]"

# 6) External profile resolution and the default secret-handling contract.
check "external profile was applied" "docker logs '${APP}' 2>&1 | grep -F '[minimal] profile applied' >/dev/null"
check "credential secrets absent from default logs" "! docker logs '${APP}' 2>&1 | grep 'API User Credentials' >/dev/null"
check "credential file exists" "docker exec '${APP}' test -s /var/www/api-credentials.txt"
cred_mode=$(docker exec "${APP}" stat -c '%a' /var/www/api-credentials.txt 2>/dev/null || true)
check "credential file mode is 0600 (got ${cred_mode:-absent})" "[ '${cred_mode}' = '600' ]"
cred_line=$(docker exec "${APP}" sed -n '1p' /var/www/api-credentials.txt 2>/dev/null || true)
check "external profile generated random password + API key" "echo '${cred_line}' | grep -Eq '^smokeapi:[0-9a-f]{48}:[0-9a-f]{32}$'"
check "password is not derived from username" "! echo '${cred_line}' | grep -q '^smokeapi:smokeapi:'"

if [ "${fail}" = 0 ]; then echo "==> PASS: ${IMAGE} on ${DATABASE_IMAGE}"; else echo "==> FAIL: ${IMAGE} on ${DATABASE_IMAGE}"; exit 1; fi
