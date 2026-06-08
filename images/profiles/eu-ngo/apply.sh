#!/bin/bash
# Apply the "eu-ngo" demo profile (extensions + seed data + API users) to the
# freshly-baked civibuild site. Runs at BUILD time (buildkit Dockerfile `demo`
# stage, DEMO_PROFILE=eu-ngo) as the buildkit user, with the embedded MariaDB
# already up. This is the build-time replacement for the old runtime block in
# allinone/entrypoint-allinone.sh — done once at bake time so the demo image
# boots ready.
#
#   apply.sh <profile-dir>     # e.g. /tmp/civikitchen-profiles/eu-ngo
set -euo pipefail

PROFILE_DIR="${1:?usage: apply.sh <profile-dir>}"
JSON="${PROFILE_DIR}/profile.json"
SITE_WEB="/home/buildkit/buildkit/build/site/web"
export PATH="/home/buildkit/buildkit/bin:${PATH}"

cd "${SITE_WEB}"

# Extension dir (cv-discovered, so this stays CMS-agnostic even though eu-ngo is
# drupal10-only today). The embedded DB is up at this point, so cv can boot.
EXT_DIR="$(cv ev 'echo rtrim(CRM_Core_Config::singleton()->extensionsDir, "/");')"
[ -n "${EXT_DIR}" ] || { echo "apply.sh: could not resolve extensionsDir" >&2; exit 1; }
mkdir -p "${EXT_DIR}"

echo "==> [eu-ngo] cloning extensions into ${EXT_DIR}"
# Tab-separated so URLs/names never collide with the field separator. A failed
# clone/checkout aborts the build (loud) — a missing extension must not ship.
jq -r '.dependencies[] | "\(.repo)\t\(.name)\t\(.version)"' "${JSON}" \
| while IFS=$'\t' read -r repo name version; do
    if [ -d "${EXT_DIR}/${name}" ]; then echo "  ${name} already present"; continue; fi
    echo "  cloning ${name} @ ${version}"
    git clone --quiet "${repo}" "${EXT_DIR}/${name}"
    # Strict checkout of the ref named in profile.json — a missing ref aborts the
    # build (these are all real default branches; verified with `git ls-remote`).
    # No silent fallback: a wrong pin must fail loudly, not ship something else.
    git -C "${EXT_DIR}/${name}" checkout --quiet "${version}"
done

echo "==> [eu-ngo] enabling extensions"
mapfile -t ENABLE < <(jq -r '.dependencies[] | select(.enable) | .name' "${JSON}")
cv ext:enable "${ENABLE[@]}"

echo "==> [eu-ngo] seeding demo data"
# Seeds are best-effort (parity with the old runtime flow, which hid seed
# failures): one flaky seeder must not fail the whole image build.
SEEDS_DIR="${PROFILE_DIR}/seeds" bash "${PROFILE_DIR}/seed-loader.sh" all \
    || echo "  WARN: one or more seeders failed (non-fatal)"

echo "==> [eu-ngo] configuring API users + AuthX"
bash "${PROFILE_DIR}/configure-api-users.sh" "${JSON}"

cv flush
echo "==> [eu-ngo] profile applied"
