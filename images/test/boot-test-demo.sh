#!/bin/bash
# Boot test for the single-container DEMO images (civikitchen:*-demo). Unlike
# images/test/boot-test.sh (which spins up an external MariaDB sidecar), the
# demo image is self-contained: `docker run` it, wait for the HEALTHCHECK, and
# assert the baked site + embedded DB come up. This guards the build-time bake
# of the embedded DB and the demo entrypoint. An optional second argument
# boots with CIVIKITCHEN_PROFILE=<profile> and additionally asserts the
# runtime profile apply worked (extension installed, credentials in the logs).
#
# Usage:
#   bash images/test/boot-test-demo.sh <image> [profile]
#   bash images/test/boot-test-demo.sh civikitchen:drupal10-demo
#   bash images/test/boot-test-demo.sh civikitchen:drupal10-demo verein
set -euo pipefail

IMAGE="${1:?usage: boot-test-demo.sh <image> [profile]}"
PROFILE="${2:-}"

# Same name-length cap as boot-test.sh: keep container names DNS-label-safe
# (<63 chars) even with a 40-char sha in the CI image tag.
RAW="$(echo "${IMAGE}${PROFILE:+-${PROFILE}}" | tr -c 'a-z0-9' '-')"
SLUG="demotest-$(echo "${RAW}" | cut -c1-32)$(echo "${RAW}" | cksum | cut -d' ' -f1)"
APP="${SLUG}-app"
if [ -n "${PROFILE}" ]; then
    HEALTH_TIMEOUT=900   # profile apply = git clones + seeds at first boot
else
    HEALTH_TIMEOUT=300   # baked site → first boot is fast; allow slack
fi

cleanup() { docker rm -f "${APP}" >/dev/null 2>&1 || true; rm -f "${LOGFILE:-}"; }
trap cleanup EXIT

echo "==> boot-test (demo) ${IMAGE}${PROFILE:+ profile=${PROFILE}}"
docker rm -f "${APP}" >/dev/null 2>&1 || true
docker run -d --name "${APP}" \
    ${PROFILE:+-e CIVIKITCHEN_PROFILE=${PROFILE}} \
    "${IMAGE}" >/dev/null

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

# Snapshot the container logs once for the log-based checks below: piping
# `docker logs` straight into `grep -q` dies of SIGPIPE under pipefail when
# grep exits on an early match, turning real matches into false negatives.
LOGFILE="$(mktemp)"
docker logs "${APP}" >"${LOGFILE}" 2>&1

# 4) Embedded DB started clean from the baked data dir (no InnoDB crash recovery).
if grep -qiE 'InnoDB.*(crash recovery|Starting crash recovery|Recovering)' "${LOGFILE}"; then
    echo "  ✗ embedded MariaDB did InnoDB crash recovery (baked data dir not cleanly shut down)"; fail=1
else
    echo "  ✓ embedded MariaDB started clean (no InnoDB recovery)"
fi

# 5+6) Runtime profile apply worked: the profile's first enabled extension is
# installed, and the API credentials were printed to the container logs.
if [ -n "${PROFILE}" ]; then
    ext_key=$(docker exec "${APP}" \
        jq -r '[.dependencies[] | select(.enable)][0].name // empty' \
        "/usr/local/share/civikitchen/profiles/${PROFILE}/profile.json" 2>/dev/null || true)
    if [ -n "${ext_key}" ]; then
        status=$(docker exec -u buildkit -w /home/buildkit/buildkit/build/site/web "${APP}" \
            bash -lc "export PATH=/home/buildkit/buildkit/bin:\$PATH; cv api4 Extension.get +w key=${ext_key} +w status=installed +s key 2>/dev/null" || true)
        check "profile extension ${ext_key} installed" "echo '${status}' | grep -q '${ext_key}'"
    else
        echo "  ✗ could not read profile.json for '${PROFILE}' from the image"; fail=1
    fi
    check "API credentials printed to docker logs" \
        "grep -q 'API User Credentials' '${LOGFILE}'"
    # Seeds are deliberately non-fatal in apply.sh (one flaky seeder must not
    # block the boot), so a broken seed still turns the container healthy —
    # catch it here instead.
    if grep -q 'WARN: .*failed (non-fatal)' "${LOGFILE}"; then
        echo "  ✗ profile seed failure in logs:"; grep 'WARN: .*failed (non-fatal)' "${LOGFILE}"; fail=1
    else
        echo "  ✓ no profile seed failures in logs"
    fi
fi

[ "${fail}" = 0 ] && echo "==> PASS: ${IMAGE}" || { echo "==> FAIL: ${IMAGE}"; exit 1; }
