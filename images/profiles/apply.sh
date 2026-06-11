#!/bin/bash
# Shared profile driver: apply a demo profile (extensions + seed data + API
# users) to a civibuild site. Runs at FIRST BOOT as the buildkit user, invoked
# by provision.sh's ck_apply_profile when CIVIKITCHEN_PROFILE=<name> is set —
# works on the demo images (embedded DB) and the dev images (external DB)
# alike. Needs network and takes a few minutes; it is marker-gated by the
# caller, so it applies exactly once per container.
#
# Entirely driven by the profile dir (profile.json + seeds/*.php), so every
# profile shares this one script; a profile may ship its own apply.sh to
# override it. Each dependency in profile.json names one of three sources:
#   "repo": <git url>     cloned at "version" into the site's ext dir
#   "registry": true      cv ext:download (packaged release incl. built assets)
#   neither               bundled with core (e.g. flexmailer), enable only
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

# Keys of every extension the site can already see, across ALL scanned paths —
# civibuild bakes some demo extensions under civicrm-core/tools/extensions
# (e.g. org.civicoop.civirules on drupal10-demo). Cloning a second copy of a
# known key is fatal (PHP redeclares the module functions), so both fetch
# steps below skip keys from this list.
LOCAL_KEYS="$(cv ev 'foreach (CRM_Extension_System::singleton()->getFullContainer()->getKeys() as $k) { echo $k . PHP_EOL; }')"
ext_present() { grep -qx "$1" <<<"${LOCAL_KEYS}" || [ -d "${EXT_DIR}/$1" ]; }

echo "==> [${PROFILE_NAME}] cloning extensions into ${EXT_DIR}"
# Tab-separated so URLs/names never collide with the field separator. A failed
# clone/checkout aborts the apply (loud) — a missing extension must not ship.
jq -r '.dependencies[] | select(.repo) | "\(.repo)\t\(.name)\t\(.version)"' "${JSON}" \
| while IFS=$'\t' read -r repo name version; do
    if ext_present "${name}"; then echo "  ${name} already present"; continue; fi
    echo "  cloning ${name} @ ${version}"
    git clone --quiet "${repo}" "${EXT_DIR}/${name}"
    # Strict checkout of the ref named in profile.json — a missing ref aborts
    # the apply. No silent fallback: a wrong pin must fail loudly, not ship
    # something else.
    git -C "${EXT_DIR}/${name}" checkout --quiet "${version}"
done

echo "==> [${PROFILE_NAME}] downloading registry extensions"
jq -r '.dependencies[] | select(.registry == true) | .name' "${JSON}" \
| while IFS= read -r name; do
    [ -n "${name}" ] || continue
    if ext_present "${name}"; then echo "  ${name} already present"; continue; fi
    echo "  downloading ${name}"
    cv ext:download "${name}"
done

echo "==> [${PROFILE_NAME}] enabling extensions"
# One at a time, in profile.json order: a batched Extension.install does not
# guarantee install order, and extension installers may depend on artifacts
# (option groups etc.) created by an earlier dependency's installer. Also
# covers bundled core extensions like flexmailer (no repo, no registry).
jq -r '.dependencies[] | select(.enable) | .name' "${JSON}" \
| while IFS= read -r name; do
    [ -n "${name}" ] || continue
    cv ext:enable "${name}"
done

echo "==> [${PROFILE_NAME}] seeding demo data"
# Seeds are PHP scripts run with a booted CiviCRM (cv scr): one process per
# seed instead of one per API call, with real loops + error handling. Run as
# the admin CMS user — extensions like CiviSEPA make internal API calls that
# re-check permissions regardless of the caller's check_permissions flag.
# Ordered by filename prefix; each seed is best-effort — one flaky seeder must
# not abort the apply (everything before the seeds is hard-fail). The boot
# test fails on the WARN line, so CI still catches a broken seed.
for seed in "${PROFILE_DIR}/seeds/"*.php; do
    [ -e "${seed}" ] || continue
    echo "  -> $(basename "${seed}")"
    cv scr --user=admin "${seed}" || echo "  WARN: $(basename "${seed}") failed (non-fatal)"
done

if jq -e '.apiUsers' "${JSON}" >/dev/null 2>&1; then
    echo "==> [${PROFILE_NAME}] configuring API users + AuthX"
    bash "$(dirname "$0")/configure-api-users.sh" "${JSON}"
fi

cv flush
echo "==> [${PROFILE_NAME}] profile applied"
