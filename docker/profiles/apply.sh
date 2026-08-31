#!/bin/bash
# Shared profile driver: apply a demo profile (extensions + seed data + API
# users) to a site. Runs at FIRST BOOT as the web user, invoked by
# provision.sh's ck_apply_profile when CIVIKITCHEN_PROFILE=<name> is set —
# works on every flavor (standalone, drupal10, drupal11, wordpress, joomla),
# on demo images (embedded DB) and dev images (external DB) alike. Needs network
# and takes a few minutes; it is marker-gated by the caller, so it applies
# exactly once per container.
#
# Entirely driven by the profile dir (profile.json + seeds/*.php), so every
# profile shares this one script; a profile may ship its own apply.sh to
# override it. Each dependency in profile.json names one of three sources:
#   "repo": <git url>     cloned at "version" into the site's ext dir
#   "registry": true      cv ext:download (packaged release incl. built assets)
#   neither               bundled with core (e.g. flexmailer), enable only
# The full, canonical profile.json shape is packages/civicrm-profile-schema/
# profile.schema.json (draft 2020-12, enforced in CI; published as
# @jfilter/civicrm-profile-schema).
#
#   apply.sh <profile-dir>     # e.g. /usr/local/share/civikitchen/profiles/verein
set -euo pipefail

# Packaged code replaced by an immutable Git pin remains recoverable until the
# complete profile succeeds. A failed enable/seed/user step restores the prior
# filesystem state; installed packaged extensions are refused below because a
# database upgrade cannot be rolled back safely by swapping PHP files.
PROFILE_SUCCEEDED=0
declare -a REPLACED_TARGETS=() REPLACED_BACKUPS=()
ck_profile_cleanup() {
    local index target backup
    for index in "${!REPLACED_TARGETS[@]}"; do
        target="${REPLACED_TARGETS[${index}]}"
        backup="${REPLACED_BACKUPS[${index}]}"
        if [ "${PROFILE_SUCCEEDED}" = 1 ]; then
            rm -rf "${backup}"
        elif [ -e "${backup}" ]; then
            rm -rf "${target}"
            mv "${backup}" "${target}"
            echo "  restored packaged extension after failed profile: ${target}" >&2
        fi
    done
}
trap ck_profile_cleanup EXIT

PROFILE_DIR="${1:?usage: apply.sh <profile-dir>}"
PROFILE_NAME="$(basename "${PROFILE_DIR}")"
JSON="${PROFILE_DIR}/profile.json"
CK_PROFILE_CLI="$(command -v ck 2>/dev/null || true)"
if [ -z "${CK_PROFILE_CLI}" ]; then
    CK_PROFILE_CLI="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/toolbelt/bin/ck"
fi
[ -x "${CK_PROFILE_CLI}" ] || { echo "apply.sh: cannot find the shared ck PHP CLI" >&2; exit 1; }
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
LOCAL_EXTENSIONS="$(cv ev '$c=CRM_Extension_System::singleton()->getFullContainer(); foreach ($c->getKeys() as $k) { echo $k . "\t" . $c->getPath($k) . PHP_EOL; }')"
LOCAL_KEYS="$(cut -f1 <<<"${LOCAL_EXTENSIONS}")"
ext_present() { grep -qx "$1" <<<"${LOCAL_KEYS}" || [ -d "${EXT_DIR}/$1" ]; }
extension_key() {
    "${CK_PROFILE_CLI}" internal extension-key "$1/info.xml" 2>/dev/null || true
}

# The UF (CMS framework) this site runs on — "Standalone", "WordPress",
# "Drupal8", ... A dependency may declare `"skipUf": ["Standalone"]` (values
# compared against CIVICRM_UF verbatim) plus an optional human "skipUfReason";
# it is then neither fetched nor enabled on that framework. This exists
# because an extension can be structurally incompatible with one flavor —
# e.g. remoteevent's `civicrm_session` table DROPs/replaces standaloneusers'
# session storage on Standalone, after which every web request fatals
# (https://github.com/systopia/de.systopia.remoteevent/issues/128).
UF="$(cv ev 'echo CIVICRM_UF;')"
# Generated users receive passwords and API keys, but CiviKitchen does not mint
# JWTs. Validate the effective merged policy before fetching or replacing code.
if "${CK_PROFILE_CLI}" internal profile-api-users-present "${JSON}"; then
    AUTHX_POLICY="${CK_AUTHX_HEADER_CRED:-$("${CK_PROFILE_CLI}" internal profile-authx-policy "${JSON}")}"
    if [[ ",${AUTHX_POLICY}," != *,api_key,* && ",${AUTHX_POLICY}," != *,pass,* ]]; then
        echo "apply.sh: API users need an AuthX policy containing api_key or pass; JWT credentials are not generated" >&2
        exit 1
    fi
    if [ "${UF}" = Joomla ] && [[ ",${AUTHX_POLICY}," != *,api_key,* ]]; then
        echo "apply.sh: Joomla API users require api_key authentication" >&2
        exit 1
    fi
fi
"${CK_PROFILE_CLI}" internal profile-skipped "${JSON}" "${UF}"

echo "==> [${PROFILE_NAME}] cloning extensions into ${EXT_DIR}"
# Tab-separated so URLs/names never collide with the field separator. A failed
# clone/checkout aborts the apply (loud) — a missing extension must not ship.
while IFS=$'\t' read -r repo name version; do
    target="${EXT_DIR}/${name}"
    # A civibuild image can expose bundled extensions from another scanned
    # directory. Converge the discovered code in place; silently accepting it
    # would bypass the profile's immutable commit pin.
    if [ ! -d "${target}" ]; then
        discovered="$(awk -F '\t' -v key="${name}" '$1 == key {sub(/^[^\t]*\t/, ""); print; exit}' <<<"${LOCAL_EXTENSIONS}")"
        [ -z "${discovered}" ] || target="${discovered}"
    fi
    if [ -d "${target}" ]; then
        if [ ! -d "${target}/.git" ]; then
            # civibuild may bake a release archive directly into the primary
            # extension directory and then strip every .git directory. A
            # profile with a pinned Git source must still converge that code
            # to the requested commit. Only replace a directory which CiviCRM
            # already recognizes under this exact extension key; arbitrary or
            # half-created directories remain a hard error.
            existing_key="$(extension_key "${target}")"
            if ! grep -qx "${name}" <<<"${LOCAL_KEYS}" || [ "${existing_key}" != "${name}" ]; then
                echo "  ERROR: ${target} already exists but is not a verifiable git checkout" >&2
                exit 1
            fi
            if ! db_installed_keys="$(cv ext:list --statuses=installed,disabled --columns=key --out=list 2>/dev/null)"; then
                echo "  ERROR: could not determine database lifecycle status for ${name}" >&2
                exit 1
            fi
            if grep -qx "${name}" <<<"${db_installed_keys}"; then
                echo "  ERROR: refusing to replace database-installed packaged extension ${name}; its lifecycle cannot be rolled back safely" >&2
                exit 1
            fi
            if ! uninstalled_keys="$(cv ext:list --statuses=uninstalled --columns=key --out=list 2>/dev/null)"; then
                echo "  ERROR: could not determine uninstalled extension status for ${name}" >&2
                exit 1
            fi
            if ! grep -qx "${name}" <<<"${uninstalled_keys}"; then
                echo "  ERROR: refusing to replace ${name} with unknown extension lifecycle status" >&2
                exit 1
            fi
            echo "  replacing packaged ${name} with pinned commit ${version}"
            temporary="$(mktemp -d "${EXT_DIR}/.${name}.clone.XXXXXX")"
            if ! git clone --quiet "${repo}" "${temporary}" \
              || ! git -C "${temporary}" checkout --quiet "${version}"; then
                rm -rf "${temporary}"
                echo "  ERROR: could not clone ${name} at requested ref ${version}" >&2
                exit 1
            fi
            if [ "$(extension_key "${temporary}")" != "${name}" ]; then
                rm -rf "${temporary}"
                echo "  ERROR: cloned repository does not declare extension key ${name}" >&2
                exit 1
            fi
            packaged="$(dirname "${target}")/.$(basename "${target}").packaged.$$"
            mv "${target}" "${packaged}"
            if ! mv "${temporary}" "${target}"; then
                mv "${packaged}" "${target}"
                rm -rf "${temporary}"
                echo "  ERROR: could not install pinned ${name}" >&2
                exit 1
            fi
            REPLACED_TARGETS+=("${target}")
            REPLACED_BACKUPS+=("${packaged}")
            continue
        fi
        expected="$(git -C "${target}" rev-parse --verify "${version}^{commit}" 2>/dev/null || true)"
        actual="$(git -C "${target}" rev-parse --verify HEAD 2>/dev/null || true)"
        if [ -z "${expected}" ] || [ "${actual}" != "${expected}" ]; then
            echo "  ERROR: ${target} exists at ${actual:-unknown}, not requested ref ${version}" >&2
            exit 1
        fi
        echo "  ${name} already present at ${version}"
        continue
    fi
    # A bundled/alternate extension path can satisfy this key without owning
    # EXT_DIR/name. Do not clone a duplicate copy into the primary directory.
    if grep -qx "${name}" <<<"${LOCAL_KEYS}"; then echo "  ${name} already present"; continue; fi
    echo "  cloning ${name} @ ${version}"
    temporary="$(mktemp -d "${EXT_DIR}/.${name}.clone.XXXXXX")"
    # Clone and verify away from the discovery path. If checkout fails, no
    # partial directory can make the next boot believe the dependency exists.
    if ! git clone --quiet "${repo}" "${temporary}" \
      || ! git -C "${temporary}" checkout --quiet "${version}"; then
        rm -rf "${temporary}"
        echo "  ERROR: could not clone ${name} at requested ref ${version}" >&2
        exit 1
    fi
    if [ "$(extension_key "${temporary}")" != "${name}" ]; then
        rm -rf "${temporary}"
        echo "  ERROR: cloned repository does not declare extension key ${name}" >&2
        exit 1
    fi
    mv "${temporary}" "${target}"
done < <("${CK_PROFILE_CLI}" internal profile-dependencies "${JSON}" "${UF}" repo)

echo "==> [${PROFILE_NAME}] downloading registry extensions"
"${CK_PROFILE_CLI}" internal profile-dependencies "${JSON}" "${UF}" registry \
| while IFS= read -r name; do
    [ -n "${name}" ] || continue
    if ext_present "${name}"; then echo "  ${name} already present"; continue; fi
    echo "  downloading ${name}"
    cv ext:download -n --no-install "${name}"
done

echo "==> [${PROFILE_NAME}] enabling extensions"
# One at a time, in profile.json order: a batched Extension.install does not
# guarantee install order, and extension installers may depend on artifacts
# (option groups etc.) created by an earlier dependency's installer. Also
# covers bundled core extensions like flexmailer (no repo, no registry).
"${CK_PROFILE_CLI}" internal profile-dependencies "${JSON}" "${UF}" enable \
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
# Ordered by filename prefix. A failed seed aborts the apply so the caller does
# not write its provisioning marker; the next boot can then retry the complete
# idempotent profile instead of preserving a partially seeded healthy site.
for seed in "${PROFILE_DIR}/seeds/"*.php; do
    [ -e "${seed}" ] || continue
    echo "  -> $(basename "${seed}")"
    if ! cv scr --user=admin "${seed}"; then
        echo "  ERROR: profile seed failed: $(basename "${seed}")" >&2
        exit 1
    fi
done

if "${CK_PROFILE_CLI}" internal profile-api-users-declared "${JSON}"; then
    echo "==> [${PROFILE_NAME}] preparing API users + AuthX"
    # authx powers the api_key / basic-auth the API users rely on. It ships with
    # core but isn't enabled on every CMS build (civibuild's joomla-demo leaves
    # it uninstalled), so enable it here — in its own process, before the script
    # below uses it. Idempotent, and a no-op where it is already on.
    cv ext:enable authx
    # PHP via cv scr: cv boots CiviCRM + the host CMS, so user/role creation
    # uses the native CMS APIs on every flavor (no drush/wp-cli dependency).
    if [ "${CK_DEFER_API_USERS:-0}" != 1 ]; then
        CK_PROFILE_JSON="${JSON}" CK_AUTHX_HEADER_CRED="${CK_AUTHX_HEADER_CRED:-}" \
          cv scr "$(dirname "$0")/configure-api-users.php"
    fi
fi

cv flush
PROFILE_SUCCEEDED=1
echo "==> [${PROFILE_NAME}] profile applied"
