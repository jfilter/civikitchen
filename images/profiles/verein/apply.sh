#!/bin/bash
# Apply a demo profile (extensions + seed data + API users) to a civibuild
# site. Runs at FIRST BOOT as the buildkit user, invoked by provision.sh's
# ck_apply_profile when CIVIKITCHEN_PROFILE=<name> is set — works on the demo
# images (embedded DB) and the dev images (external DB) alike. Needs network
# (git clones) and takes a few minutes; it is marker-gated by the caller, so
# it applies exactly once per container.
#
# The script is profile-agnostic (driven by profile.json + seeds/*.php in the
# profile dir) so new profiles can reuse it verbatim.
#
#   apply.sh <profile-dir>     # e.g. /usr/local/share/civikitchen/profiles/verein
set -euo pipefail

PROFILE_DIR="${1:?usage: apply.sh <profile-dir>}"
PROFILE_NAME="$(basename "${PROFILE_DIR}")"
JSON="${PROFILE_DIR}/profile.json"
SITE_WEB="/home/buildkit/buildkit/build/site/web"
export PATH="/home/buildkit/buildkit/bin:${PATH}"

cd "${SITE_WEB}"

# Extension dir (cv-discovered, so this stays CMS-agnostic even though the
# profiles are drupal10-only today). The DB is up at this point, so cv boots.
EXT_DIR="$(cv ev 'echo rtrim(CRM_Core_Config::singleton()->extensionsDir, "/");')"
[ -n "${EXT_DIR}" ] || { echo "apply.sh: could not resolve extensionsDir" >&2; exit 1; }
mkdir -p "${EXT_DIR}"

echo "==> [${PROFILE_NAME}] cloning extensions into ${EXT_DIR}"
# Tab-separated so URLs/names never collide with the field separator. A failed
# clone/checkout aborts the apply (loud) — a missing extension must not ship.
jq -r '.dependencies[] | "\(.repo)\t\(.name)\t\(.version)"' "${JSON}" \
| while IFS=$'\t' read -r repo name version; do
    if [ -d "${EXT_DIR}/${name}" ]; then echo "  ${name} already present"; continue; fi
    echo "  cloning ${name} @ ${version}"
    git clone --quiet "${repo}" "${EXT_DIR}/${name}"
    # Strict checkout of the ref named in profile.json — a missing ref aborts
    # the apply. No silent fallback: a wrong pin must fail loudly, not ship
    # something else.
    git -C "${EXT_DIR}/${name}" checkout --quiet "${version}"
done

echo "==> [${PROFILE_NAME}] enabling extensions"
mapfile -t ENABLE < <(jq -r '.dependencies[] | select(.enable) | .name' "${JSON}")
cv ext:enable "${ENABLE[@]}"

echo "==> [${PROFILE_NAME}] seeding demo data"
# Seeds are PHP scripts run with a booted CiviCRM (cv scr): one process per
# seed instead of one per API call, with real loops + error handling. Run as
# the admin CMS user — extensions like CiviSEPA make internal API calls that
# re-check permissions regardless of the caller's check_permissions flag.
# Ordered by filename prefix; each seed is best-effort — one flaky seeder must
# not abort the apply (everything before the seeds is hard-fail).
for seed in "${PROFILE_DIR}/seeds/"*.php; do
    [ -e "${seed}" ] || continue
    echo "  -> $(basename "${seed}")"
    cv scr --user=admin "${seed}" || echo "  WARN: $(basename "${seed}") failed (non-fatal)"
done

echo "==> [${PROFILE_NAME}] configuring API users + AuthX"
bash "${PROFILE_DIR}/configure-api-users.sh" "${JSON}"

cv flush
echo "==> [${PROFILE_NAME}] profile applied"
