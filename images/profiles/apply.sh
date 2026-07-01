#!/bin/bash
# Shared profile driver: apply a demo profile (extensions + seed data + API
# users) to a site. Runs at FIRST BOOT as the web user, invoked by
# provision.sh's ck_apply_profile when CIVIKITCHEN_PROFILE=<name> is set —
# works on the tested profile flavors (standalone, drupal10, wordpress), on
# demo images (embedded DB) and dev images (external DB) alike. Needs network
# and takes a few minutes; it is marker-gated by the caller, so it applies
# exactly once per container.
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
# civibuild layout if present (demo + buildkit dev images); on the standalone
# dev image cv is on the global PATH and finds the site via env, no cd needed.
SITE_WEB="/home/buildkit/buildkit/build/site/web"
if [ -d "${SITE_WEB}" ]; then
    export PATH="/home/buildkit/buildkit/bin:${PATH}"
    cd "${SITE_WEB}"
fi

# Extension dir (cv-discovered, so this stays CMS-agnostic across the tested
# profile flavors). The DB is up at this point, so cv boots.
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

# The UF (CMS framework) this site runs on — "Standalone", "WordPress",
# "Drupal8", ... A dependency may declare `"skipUf": ["Standalone"]` (values
# compared against CIVICRM_UF verbatim) plus an optional human "skipUfReason";
# it is then neither fetched nor enabled on that framework. This exists
# because an extension can be structurally incompatible with one flavor —
# e.g. remoteevent's `civicrm_session` table DROPs/replaces standaloneusers'
# session storage on Standalone, after which every web request fatals
# (https://github.com/systopia/de.systopia.remoteevent/issues/128).
UF="$(cv ev 'echo CIVICRM_UF;')"
# jq filter fragment: dependencies NOT skipped on this UF.
NOT_SKIPPED='select((.skipUf // []) | index($uf) | not)'
jq -r --arg uf "${UF}" \
    '.dependencies[] | select((.skipUf // []) | index($uf))
     | "  SKIP \(.name) on \($uf): \(.skipUfReason // "declared incompatible in profile.json")"' \
    "${JSON}"

echo "==> [${PROFILE_NAME}] cloning extensions into ${EXT_DIR}"
# Tab-separated so URLs/names never collide with the field separator. A failed
# clone/checkout aborts the apply (loud) — a missing extension must not ship.
jq -r --arg uf "${UF}" ".dependencies[] | ${NOT_SKIPPED} | select(.repo) | \"\(.repo)\t\(.name)\t\(.version)\"" "${JSON}" \
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
jq -r --arg uf "${UF}" ".dependencies[] | ${NOT_SKIPPED} | select(.registry == true) | .name" "${JSON}" \
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
jq -r --arg uf "${UF}" ".dependencies[] | ${NOT_SKIPPED} | select(.enable) | .name" "${JSON}" \
| while IFS= read -r name; do
    [ -n "${name}" ] || continue
    cv ext:enable "${name}"
done

# On Joomla, civibuild's install doesn't link the CMS admin to a CiviCRM contact
# (Drupal/WordPress/Standalone do at install time), so cv --user=admin below
# can't resolve a contact. Create the link the way CiviCRM does (idempotent).
if [ "${UF}" = "Joomla" ]; then
    cv scr "$(dirname "$0")/joomla-link-admin.php"
fi

# Seeds run as the admin CMS user. civibuild sites always have one; the
# standalone dev image only after an auto-install with a demo admin — fail
# with a hint instead of a cryptic cv error per seed.
if ! cv ev --user=admin 'echo "ok";' >/dev/null 2>&1; then
    echo "apply.sh: no 'admin' user on this site — profiles need one." >&2
    echo "apply.sh: on the standalone dev image set CIVICRM_AUTO_INSTALL=1 and CIVIKITCHEN_DEMO_USER=admin." >&2
    exit 1
fi

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
    # The WARN wording is load-bearing: images/test/boot-test-demo.sh and
    # external consumers grep the logs for `WARN: .*failed \(non-fatal\)` —
    # don't reword without updating them.
    cv scr --user=admin "${seed}" || echo "  WARN: $(basename "${seed}") failed (non-fatal)"
done

if jq -e '.apiUsers' "${JSON}" >/dev/null 2>&1; then
    echo "==> [${PROFILE_NAME}] configuring API users + AuthX"
    # authx powers the api_key / basic-auth the API users rely on. It ships with
    # core but isn't enabled on every CMS build (civibuild's joomla-demo leaves
    # it uninstalled), so enable it here — in its own process, before the script
    # below uses it. Idempotent, and a no-op where it is already on.
    cv ext:enable authx
    # PHP via cv scr: cv boots CiviCRM + the host CMS, so user/role creation
    # uses the native CMS APIs on every flavor (no drush/wp-cli dependency).
    CK_PROFILE_JSON="${JSON}" cv scr "$(dirname "$0")/configure-api-users.php"
fi

cv flush
echo "==> [${PROFILE_NAME}] profile applied"
