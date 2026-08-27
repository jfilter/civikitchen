#!/bin/bash
# civikitchen shared provisioning library.
#
# Sourced by both entrypoints AFTER their CiviCRM site exists (standalone:
# `cv core:install`; buildkit: `civibuild create site`). It holds the
# CMS-agnostic first-boot provisioning so the standalone and buildkit images
# behave the same: auto-composer for bind-mounted extensions, dev settings,
# SMTP backend, an isolated test DB, core language files, registry + mounted
# extension enabling (with the mounted extensions' <requires> resolved),
# named profiles (CIVIKITCHEN_PROFILE), and /civikitchen-init.d hooks.
#
# Caller contract — define this BEFORE calling any ck_* function:
#
#   ck_as_web CMD...   Run CMD as the image's web user, against its CiviCRM
#                      site. cv must auto-detect the site.
#                        standalone: ck_as_web() { runuser -u www-data -- "$@"; }
#                        buildkit:   cd into the site docroot + su to buildkit.
#
# The CK_* parameters below default to the standalone image's layout; the
# buildkit entrypoint overrides them before sourcing.

# --- parameters (defaults = standalone layout) -----------------------------
: "${CK_WEB_USER:=www-data}"
: "${CK_WEB_GROUP:=www-data}"
: "${CK_WEB_USER_HOME:=/var/www}"
: "${CK_EXT_DIR:=/var/www/html/ext}"
: "${CK_DATA_DIRS:=/var/www/html/private /var/www/html/public}"
: "${CK_PROVISIONED_MARKER:=/var/www/html/private/.civikitchen-provisioned}"
# Separate marker for the standalone post-install CONFIG bundle (ck_post_install_config:
# dev settings / SMTP / test DB / auth / demo user). Kept distinct from
# CK_PROVISIONED_MARKER so config and provisioning retry independently.
: "${CK_CONFIGURED_MARKER:=/var/www/html/private/.civikitchen-configured}"
: "${CK_INIT_D:=/civikitchen-init.d}"
# ~/.cv.json site key under which TEST_DB_DSN is stored. Standalone keys by its
# bootstrap file; other CMSes key the site differently (resolved per image).
: "${CK_TEST_DB_CV_KEY:=/var/www/html/civicrm.standalone.php}"
# Boot stub patched by ck_setup_test_db so CIVICRM_UF=UnitTests boots define
# the test DSN before core's env-based DSN composition (see
# patch-test-db-boot.php). Empty or missing file = skip (buildkit flavors
# boot through their CMS, not a stub).
: "${CK_BOOT_STUB:=/var/www/html/civicrm.standalone.php}"
# Where named profiles (docker/profiles/<name>/) ship inside the image.
: "${CK_PROFILE_DIR:=/usr/local/share/civikitchen/profiles}"
# Optional civibuild-style settings.d dir (loaded into civicrm.settings.php).
# Only the buildkit entrypoint sets this; see ck_smtp.
: "${CK_SETTINGS_D:=}"
# Mount table used to tell bind-mounted extensions from downloaded ones.
: "${CK_MOUNTINFO:=/proc/self/mountinfo}"
# Where the version-matching core language tarball is fetched from.
: "${CK_L10N_BASE_URL:=https://download.civicrm.org}"

# --- functions -------------------------------------------------------------

# Auto-run composer install for bind-mounted extensions under CK_EXT_DIR.
# An extension's composer.json usually pulls dev tooling (phpunit, phpstan)
# that's not in the image; doing it here saves the manual gate before
# `vendor/bin/phpunit` works. Idempotent (skips when vendor/ exists),
# non-fatal on failure. Opt out with CIVIKITCHEN_AUTO_COMPOSER=0.
ck_auto_composer() {
    [[ "${CIVIKITCHEN_AUTO_COMPOSER:-1}" == "1" ]] || return 0
    [[ -d "${CK_EXT_DIR}" ]] || return 0
    local composer_json ext_dir ext_name
    shopt -s nullglob
    for composer_json in "${CK_EXT_DIR}"/*/composer.json; do
        ext_dir="$(dirname "${composer_json}")"
        ext_name="$(basename "${ext_dir}")"
        if [[ -d "${ext_dir}/vendor" ]]; then
            continue
        fi
        # Extensions whose lock file vendors civicrm-core (the systopia
        # dev-tooling pattern) must NOT get that vendor/ inside a running
        # CiviCRM — their autoloader would load a second core. They need a
        # runtime vendor/ built with their own tooling instead.
        if grep -q '"name": "civicrm/civicrm-core"' "${ext_dir}/composer.lock" 2>/dev/null; then
            echo "[civikitchen] WARN: skipping composer install in ext/${ext_name} — its lock file vendors civicrm/civicrm-core. Build a runtime vendor/ outside the container (composer update --no-dev) instead." >&2
            continue
        fi
        echo "[civikitchen] composer install in ext/${ext_name}..."
        if ! ( cd "${ext_dir}" && composer install --no-interaction --no-progress --prefer-dist ); then
            echo "[civikitchen] WARN: composer install failed in ext/${ext_name} — set CIVIKITCHEN_AUTO_COMPOSER=0 or fix composer.json" >&2
        fi
        # Bind mounts belong to the host user; match what composer wrote to
        # the mount owner so nothing is left root-owned on Linux hosts — also
        # after a failed install, whose half-written vendor/ would otherwise
        # block the host user (a CI runner's next checkout) from removing it.
        ck_match_mount_owner "${ext_dir}" "${ext_dir}/vendor" "${ext_dir}/composer.lock"
    done
    shopt -u nullglob
}

# chown PATHS (recursively, when they exist) to the owner of DIR, which is
# the host user of a bind mount. Silent when there is nothing to do.
ck_match_mount_owner() {
    local dir="$1" path
    shift
    for path in "$@"; do
        [[ -e "${path}" ]] || continue
        chown -R --reference="${dir}" "${path}" 2>/dev/null || true
    done
}

# Dev-mode defaults — there's no reason a dev image would want them off.
ck_dev_settings() {
    ck_as_web cv setting:set environment=Development >/dev/null
    ck_as_web cv setting:set debug_enabled=1 >/dev/null
    echo "[civikitchen] Dev settings applied (environment=Development, debug_enabled=1)."
}

# Point Civi's mail backend at an SMTP host (e.g. the maildev sidecar).
# Without this, Civi defaults to PHP mail() and outbound mail goes nowhere.
ck_smtp() {
    [[ -n "${CIVIKITCHEN_SMTP_HOST}" ]] || return 0
    local smtp_port="${CIVIKITCHEN_SMTP_PORT:-1025}"
    echo "[civikitchen] Configuring mail backend → ${CIVIKITCHEN_SMTP_HOST}:${smtp_port}..."
    ck_as_web env SMTP_HOST="${CIVIKITCHEN_SMTP_HOST}" SMTP_PORT="${smtp_port}" \
        cv ev '\Civi::settings()->set("mailing_backend", ["outBound_option" => 0, "smtpServer" => getenv("SMTP_HOST"), "smtpPort" => (int) getenv("SMTP_PORT"), "smtpAuth" => 0, "smtpUsername" => "", "smtpPassword" => ""]);'
    # buildkit only: its app/civicrm.settings.d/100-mail.php cannot see the
    # DB-level setting above (it only checks the $civicrm_setting override)
    # and so defines CIVICRM_MAIL_LOG=/dev/null — send() returns TRUE while
    # every outbound mail silently vanishes. With an explicit SMTP host there
    # is nothing left for that heuristic to decide: drop it. (The standalone
    # flavor never had it — this aligns the flavors.)
    # NOT `[[ ... ]] && rm`: as the function's last statement it returns 1
    # when CK_SETTINGS_D is empty (standalone), and the entrypoints run set -e.
    if [[ -n "${CK_SETTINGS_D}" ]]; then
        rm -f "${CK_SETTINGS_D}/100-mail.php"
    fi
}

# Enable standaloneusers — the Standalone auth provider. It backs the
# /civicrm/login route and supplies the \Civi\Api4\User entity. `cv core:install`
# does NOT reliably install it across CiviCRM versions (it was absent on
# standalone-6.12), which leaves the login route with no auth provider behind it.
# Enable it explicitly so every Standalone boot has a working auth provider —
# independent of CIVIKITCHEN_DEMO_USER, since a site without a demo user still
# needs the provider. Idempotent: standaloneusers ships bundled with civicrm-core,
# so this just enables the already-present extension. Standalone-only — buildkit
# flavors authenticate through their CMS (Drupal/WordPress/Joomla) and must NOT
# call this (their entrypoints don't).
ck_standalone_auth() {
    echo "[civikitchen] Ensuring standaloneusers (Standalone auth provider) is enabled..."
    if ! ck_as_web cv ext:enable standaloneusers; then
        echo "[civikitchen] ERROR: could not enable standaloneusers (Standalone auth provider)." >&2
        return 1
    fi
}

# Demo login user. Opt-in via CIVIKITCHEN_DEMO_USER. Needs the standaloneusers
# \Civi\Api4\User entity, which ck_standalone_auth enables (it runs first in the
# standalone config bundle, ck_post_install_config) — so this no longer enables
# standaloneusers itself. Standalone-only: buildkit flavors get their CMS login
# from civibuild and must NOT call this (their entrypoints don't).
ck_demo_user() {
    [[ -n "${CIVIKITCHEN_DEMO_USER}" ]] || return 0
    local demo_user="${CIVIKITCHEN_DEMO_USER}"
    local demo_pass="${CIVIKITCHEN_DEMO_PASS:-admin}"
    local demo_email="${CIVIKITCHEN_DEMO_EMAIL:-admin@example.org}"
    echo "[civikitchen] Creating demo user '${demo_user}'..."
    # Pass env explicitly via `env` rather than relying on preserve-environment.
    ck_as_web env DEMO_USER="${demo_user}" DEMO_PASS="${demo_pass}" DEMO_EMAIL="${demo_email}" \
        cv scr /usr/local/share/civikitchen/demo-user.php
}

# Isolated headless-test database. CIVICRM_UF=UnitTests boots the test
# framework against TEST_DB_DSN; when unset CiviCRM falls back to the MAIN
# database and a headless phpunit run wipes the dev site. Point it at a
# separate <db>_test scratch DB. Opt out with CIVIKITCHEN_TEST_DB=0; a project
# needing a different DSN can overwrite ~/.cv.json from a /civikitchen-init.d hook.
ck_setup_test_db() {
    [[ "${CIVIKITCHEN_TEST_DB:-1}" == "1" ]] || return 0
    local test_db_name="${CIVICRM_DB_NAME}_test"
    local test_db_dsn="mysql://${CIVICRM_DB_USER}:${CIVICRM_DB_PASSWORD}@${CIVICRM_DB_HOST}:${CIVICRM_DB_PORT}/${test_db_name}?new_link=true"
    echo "[civikitchen] Configuring isolated test DB → ${test_db_name} (TEST_DB_DSN)..."
    if mysql -h "${CIVICRM_DB_HOST}" -P "${CIVICRM_DB_PORT}" -u "${CIVICRM_DB_USER}" -p"${CIVICRM_DB_PASSWORD}" \
        -e "CREATE DATABASE IF NOT EXISTS \`${test_db_name}\`" 2>/dev/null; then
        # Seed the test DB from the freshly installed main DB. An EMPTY test
        # DB is unusable: the headless harness boots CiviCRM against
        # TEST_DB_DSN before \Civi\Test can (re)build any schema, and that
        # boot dies on a schema-less database. civibuild does the same
        # main→test copy for its sites.
        echo "[civikitchen] Seeding ${test_db_name} from ${CIVICRM_DB_NAME}..."
        # pipefail in a subshell: without it a failing mysqldump (missing
        # grants, dropped connection) feeds mysql empty input, the pipeline
        # exits 0, and the marker gets written over a schema-less test DB.
        local seed_err
        seed_err=$(mktemp)
        if ! (set -o pipefail; mysqldump -h "${CIVICRM_DB_HOST}" -P "${CIVICRM_DB_PORT}" -u "${CIVICRM_DB_USER}" -p"${CIVICRM_DB_PASSWORD}" \
                --single-transaction --routines --triggers "${CIVICRM_DB_NAME}" 2>>"${seed_err}" \
            | mysql -h "${CIVICRM_DB_HOST}" -P "${CIVICRM_DB_PORT}" -u "${CIVICRM_DB_USER}" -p"${CIVICRM_DB_PASSWORD}" "${test_db_name}" 2>>"${seed_err}"); then
            grep -v "Using a password" "${seed_err}" >&2 || true
            echo "[civikitchen] WARN: could not seed ${test_db_name}; grant the DB user rights on it (GRANT ALL ON \`${test_db_name//_/\\_}\`.* ...) and re-provision" >&2
        fi
        rm -f "${seed_err}"
    else
        echo "[civikitchen] WARN: could not pre-create ${test_db_name}; grant the DB user rights on it (GRANT ALL ON \`${test_db_name//_/\\_}\`.* ...) — headless tests need a seeded test DB" >&2
    fi
    # cv merges ~/.cv.json into $GLOBALS['_CV'], keyed by the site bootstrap
    # path; civicrm.settings.php reads _CV['TEST_DB_DSN'] under
    # CIVICRM_UF=UnitTests. Write it for root (docker exec default) and the web
    # user. Don't clobber an existing ~/.cv.json (a project may have set its own).
    # JSON-escape the DSN (the DB password may contain \ or ") and write both
    # files as root — interpolating the payload into a `bash -c` string would
    # let a password containing quotes execute as shell.
    local dsn_json="${test_db_dsn//\\/\\\\}"
    dsn_json="${dsn_json//\"/\\\"}"
    local cv_json
    cv_json=$(printf '{\n  "sites": {\n    "%s": {\n      "TEST_DB_DSN": "%s"\n    }\n  }\n}' "${CK_TEST_DB_CV_KEY}" "${dsn_json}")
    if [[ ! -f /root/.cv.json ]]; then
        printf '%s\n' "${cv_json}" > /root/.cv.json
        chmod 600 /root/.cv.json
    fi
    if [[ ! -f "${CK_WEB_USER_HOME}/.cv.json" ]]; then
        printf '%s\n' "${cv_json}" > "${CK_WEB_USER_HOME}/.cv.json"
        chown "${CK_WEB_USER}:${CK_WEB_GROUP}" "${CK_WEB_USER_HOME}/.cv.json"
        chmod 600 "${CK_WEB_USER_HOME}/.cv.json"
    fi

    # TEST_DB_DSN in ~/.cv.json alone is NOT enough: core's SettingsManager
    # composes CIVICRM_DSN from the CIVICRM_DB_* env vars before the settings
    # file loads, so its UnitTests/TEST_DB_DSN branch never fires in an
    # env-configured container and headless phpunit would silently hit the
    # dev DB. Patch the boot stub to define the test DSN first (idempotent).
    if [[ -n "${CK_BOOT_STUB}" ]]; then
        php /usr/local/share/civikitchen/patch-test-db-boot.php "${CK_BOOT_STUB}"
    fi
}

# Download one registry extension: a bare key (de.systopia.xcm) or key@URL
# for a pinned/forked release, passed to cv verbatim. Release-asset downloads
# (GitHub et al.) fail transiently often enough that one cURL timeout
# shouldn't abort the whole first-boot provisioning — retry before giving up.
ck_download_extension() {
    local ext_spec="$1" ext_key="${1%%@*}" attempt
    for attempt in 1 2 3; do
        if ck_as_web cv ext:download -n --no-install "${ext_spec}"; then
            return 0
        fi
        if [[ "${attempt}" == "3" ]]; then
            echo "[civikitchen] ERROR: download of ${ext_key} failed after ${attempt} attempts" >&2
            return 1
        fi
        echo "[civikitchen] WARN: download of ${ext_key} failed (attempt ${attempt}/3); retrying in 5s..." >&2
        sleep 5
    done
}

# Download + enable a comma-separated list of registry extensions. Split
# download + enable into two cv calls (the combined form bombs for some
# extensions) and so later entries see earlier ones' deps installed. A bare
# key takes the extension_source pin of a mounted extension when one names
# it, so an optional integration is pinned in the same place as a hard one.
ck_extra_extensions() {
    [[ -n "${CIVIKITCHEN_EXTRA_EXTENSIONS}" ]] || return 0
    echo "[civikitchen] Installing extra extensions: ${CIVIKITCHEN_EXTRA_EXTENSIONS}"
    local ext_spec ext_key pin
    local -a specs
    IFS=',' read -ra specs <<< "${CIVIKITCHEN_EXTRA_EXTENSIONS}"
    for ext_spec in "${specs[@]}"; do
        ext_spec="${ext_spec// /}"
        [[ -z "${ext_spec}" ]] && continue
        ext_key="${ext_spec%%@*}"
        pin=""
        if [[ "${ext_spec}" == "${ext_key}" ]]; then
            pin="$(ck_mounted_extension_source "${ext_key}")"
            ext_spec="${pin:-${ext_spec}}"
        fi
        echo "[civikitchen]   - ${ext_key}${pin:+ (pinned: ${pin#*@})}"
        ck_download_extension "${ext_spec}" || return 1
        ck_as_web cv ext:enable "${ext_key}"
    done
}

# Directories bind-mounted directly into the ext dir, one per line — the
# extensions a developer put there, as opposed to downloaded ones. Read from
# the mount table (field 5 is the mount point), so nothing has to be declared.
ck_mounted_extension_dirs() {
    [[ -n "${CK_EXT_DIR}" && -r "${CK_MOUNTINFO}" ]] || return 0
    local mount_point
    while read -r _ _ _ _ mount_point _; do
        # Mount points escape space, tab and newline as \040 \011 \012.
        mount_point="$(printf '%b' "${mount_point}")"
        if [[ "${mount_point}" == "${CK_EXT_DIR}"/* && "${mount_point#"${CK_EXT_DIR}"/}" != */* ]]; then
            echo "${mount_point}"
        fi
    done < "${CK_MOUNTINFO}" | sort -u
}

# The extension key an info.xml declares, or nothing when it cannot be read.
ck_extension_key() {
    php -r '
      libxml_use_internal_errors(TRUE);
      $xml = simplexml_load_file($argv[1]);
      if ($xml === FALSE) { exit(0); }
      echo trim((string) $xml["key"]);
    ' "$1/info.xml"
}

# The extension_source pin for KEY from any mounted extension's .ckconform.
# First hit wins; a key pinned to two different URLs is a repo problem that
# ckconform reports, not one to resolve here.
ck_mounted_extension_source() {
    local key="$1" ext_dir spec
    while IFS= read -r ext_dir; do
        spec="$(ck_extension_source "${ext_dir}" "${key}")"
        if [[ -n "${spec}" ]]; then
            echo "${spec}"
            return 0
        fi
    done < <(ck_mounted_extension_dirs)
}

# Does the site know KEY — core, downloaded, or mounted? Asked through
# APIv4 Extension.get, which reads the local extension container only:
# `cv ext:list` also contacts the extensions feed for download URLs and dies
# without network. Returns 0 present, 1 absent, 2 when the question itself
# failed — the caller must not mistake a failed lookup for a missing extension.
ck_extension_present() {
    local out
    if ! out="$(ck_as_web cv api4 Extension.get +w "key=$1" +s key --out=json-strict)"; then
        echo "[civikitchen] ERROR: could not query the extension list for $1" >&2
        return 2
    fi
    php -r '
      $list = json_decode((string) $argv[1], TRUE);
      exit(is_array($list) && $list !== [] ? 0 : 1);
    ' "${out}"
}

# <requires><ext> keys of an extension directory, one per line. simplexml,
# like ck_xml_field in the toolbelt — never a regex over XML.
ck_extension_requires() {
    php -r '
      libxml_use_internal_errors(TRUE);
      $xml = simplexml_load_file($argv[1]);
      if ($xml === FALSE) { exit(0); }
      foreach ($xml->requires->ext ?? [] as $ext) { echo trim((string) $ext), "\n"; }
    ' "$1/info.xml"
}

# The pinned download spec for a dependency the registry cannot serve: an
# `extension_source=<key>@<URL>` line in the extension's own .ckconform,
# read through ckconform so the file has exactly one parser. Prints nothing
# when there is no pin.
ck_extension_source() {
    local ext_dir="$1" key="$2" spec
    [[ -f "${ext_dir}/.ckconform" ]] || return 0
    while IFS= read -r spec; do
        spec="${spec%% -- *}"
        if [[ "${spec%%@*}" == "${key}" ]]; then
            echo "${spec}"
            return 0
        fi
    done < <(cd "${ext_dir}" && ckconform --policy extension_source)
}

# Dependencies of a mounted extension, from its info.xml <requires>: what the
# site does not have yet is downloaded — pinned via extension_source, else
# from the registry — and enabled before the extension itself, so its
# `cv ext:enable` finds them. One level deep: a dependency's own <requires>
# are core or registry extensions cv resolves on enable.
ck_resolve_requires() {
    local ext_key="$1" ext_dir="${2:-${CK_EXT_DIR}/$1}" required spec
    [[ -f "${ext_dir}/info.xml" ]] || return 0
    while IFS= read -r required; do
        [[ -z "${required}" ]] && continue
        ck_extension_present "${required}"
        case $? in
            0) continue ;;
            2) return 1 ;;
        esac
        spec="$(ck_extension_source "${ext_dir}" "${required}")"
        echo "[civikitchen]   ${ext_key} requires ${required} — installing ${spec:-it from the registry}"
        ck_download_extension "${spec:-${required}}" || return 1
        ck_as_web cv ext:enable "${required}"
    done < <(ck_extension_requires "${ext_dir}")
}

# Enable the extensions bind-mounted into the ext dir, plus the keys listed
# in CIVIKITCHEN_ENABLE_EXTENSIONS (listed ones first, in their order — the
# override for an extension that must precede another or is not a mount),
# each after its <requires> are in place. A mount whose info.xml carries no
# key is reported and skipped: a directory of files is not an extension.
ck_enable_extensions() {
    local ext_key ext_dir listed
    local -a keys=() dirs=()
    if [[ -n "${CIVIKITCHEN_ENABLE_EXTENSIONS}" ]]; then
        IFS=',' read -ra keys <<< "${CIVIKITCHEN_ENABLE_EXTENSIONS}"
    fi
    for ext_key in "${keys[@]}"; do
        ext_key="${ext_key// /}"
        [[ -z "${ext_key}" ]] && continue
        listed+=",${ext_key}"
        dirs+=("${ext_key}=${CK_EXT_DIR}/${ext_key}")
    done
    while IFS= read -r ext_dir; do
        [[ -n "${ext_dir}" ]] || continue
        ext_key="$(ck_extension_key "${ext_dir}")"
        if [[ -z "${ext_key}" ]]; then
            echo "[civikitchen] WARN: ${ext_dir} is mounted into the ext dir but has no readable info.xml key — not enabled" >&2
            continue
        fi
        [[ ",${listed:-}," == *",${ext_key},"* ]] && continue
        listed+=",${ext_key}"
        dirs+=("${ext_key}=${ext_dir}")
    done < <(ck_mounted_extension_dirs)
    [[ ${#dirs[@]} -gt 0 ]] || return 0
    echo "[civikitchen] Enabling extensions: ${listed#,}"
    for ext_dir in "${dirs[@]}"; do
        ext_key="${ext_dir%%=*}"
        ck_resolve_requires "${ext_key}" "${ext_dir#*=}" || return 1
        ck_as_web cv ext:enable "${ext_key}"
    done
}

# Core language files. Opt-in via CIVIKITCHEN_LOCALES=de_DE[,fr_FR,...]: the
# version-matching civicrm-<version>-l10n.tar.gz is streamed once and only
# the requested locales land in [civicrm.l10n], the directory core reads
# translations from. Without a core .mo there, CRM_Core_I18n never
# initialises gettext and setGettextDomain() returns early for extension
# domains too — a mounted extension's own l10n/<locale> catalogue renders
# English with no error. CIVIKITCHEN_DEFAULT_LOCALE=<locale> additionally
# sets lcMessages; it has to be one of the installed locales.
ck_locales() {
    if [[ -z "${CIVIKITCHEN_LOCALES:-}" ]]; then
        if [[ -n "${CIVIKITCHEN_DEFAULT_LOCALE:-}" ]]; then
            echo "[civikitchen] ERROR: CIVIKITCHEN_DEFAULT_LOCALE=${CIVIKITCHEN_DEFAULT_LOCALE} needs its language files — list it in CIVIKITCHEN_LOCALES" >&2
            return 1
        fi
        return 0
    fi
    local locale version l10n_dir tarball unpack
    local -a locales=() members=()
    IFS=',' read -ra locales <<< "${CIVIKITCHEN_LOCALES}"
    for locale in "${locales[@]}"; do
        locale="${locale// /}"
        [[ -z "${locale}" ]] && continue
        if [[ ! "${locale}" =~ ^[a-z]{2,3}_[A-Z]{2}$ ]]; then
            echo "[civikitchen] ERROR: '${locale}' is not a CiviCRM locale (expected e.g. de_DE)" >&2
            return 1
        fi
        members+=("civicrm/l10n/${locale}")
    done
    [[ ${#members[@]} -gt 0 ]] || return 0
    if [[ -n "${CIVIKITCHEN_DEFAULT_LOCALE:-}" && " ${members[*]} " != *" civicrm/l10n/${CIVIKITCHEN_DEFAULT_LOCALE} "* ]]; then
        echo "[civikitchen] ERROR: CIVIKITCHEN_DEFAULT_LOCALE=${CIVIKITCHEN_DEFAULT_LOCALE} is not in CIVIKITCHEN_LOCALES=${CIVIKITCHEN_LOCALES}" >&2
        return 1
    fi
    version="$(ck_as_web cv ev 'echo CRM_Utils_System::version();')"
    l10n_dir="$(ck_as_web cv path -d '[civicrm.l10n]')"
    tarball="${CK_L10N_BASE_URL}/civicrm-${version}-l10n.tar.gz"
    echo "[civikitchen] Installing core language files ${CIVIKITCHEN_LOCALES} from ${tarball} into ${l10n_dir}..."
    unpack="$(mktemp -d)"
    # Streamed, not saved: the tarball is ~100 MB and only a few MB of it are
    # wanted. tar exits non-zero for a member that is not in the archive, so an
    # unknown locale fails here instead of silently leaving English behind.
    if ! (set -o pipefail; curl -fsSL "${tarball}" | tar -xz -C "${unpack}" "${members[@]}"); then
        rm -rf "${unpack}"
        echo "[civikitchen] ERROR: could not fetch ${CIVIKITCHEN_LOCALES} from ${tarball}" >&2
        return 1
    fi
    mkdir -p "${l10n_dir}"
    cp -R "${unpack}/civicrm/l10n/." "${l10n_dir}/"
    rm -rf "${unpack}"
    chown -R "${CK_WEB_USER}:${CK_WEB_GROUP}" "${l10n_dir}"
    if [[ -n "${CIVIKITCHEN_DEFAULT_LOCALE:-}" ]]; then
        ck_as_web cv setting:set "lcMessages=${CIVIKITCHEN_DEFAULT_LOCALE}" >/dev/null
        echo "[civikitchen] Default locale set to ${CIVIKITCHEN_DEFAULT_LOCALE} (lcMessages)."
    fi
}

# Apply named profiles (extensions + seed data + API users) at first boot.
# Opt-in via CIVIKITCHEN_PROFILE=<name>[,<name>...] — a comma-separated list
# is applied left to right; profiles ship in CK_PROFILE_DIR (see
# docker/profiles/). Combining works because every layer converges instead of
# colliding: apply.sh skips extensions the site already has, seeds skip when
# their anchor org exists, and configure-api-users.php upserts users/roles
# (merging role permissions), so a name shared across profiles ends up with
# the union. Needs network (git clones) and can take several minutes.
# Runs inside ck_post_install_provision, so it is marker-gated: it applies
# once, and a failure aborts the boot (no marker) and re-runs on next start.
ck_apply_profile() {
    [[ -n "${CIVIKITCHEN_PROFILE:-}" ]] || return 0
    local name dir apply want_cms
    local -a names
    IFS=',' read -ra names <<< "${CIVIKITCHEN_PROFILE}"
    # Validate the whole list before applying anything — a typo in the last
    # name must not leave a half-provisioned site behind the failed boot.
    for name in "${names[@]}"; do
        name="${name// /}"
        [[ -z "${name}" ]] && continue
        if [[ ! -f "${CK_PROFILE_DIR}/${name}/profile.json" ]]; then
            echo "[civikitchen] ERROR: unknown profile '${name}'." >&2
            echo "[civikitchen] Available profiles: $(cd "${CK_PROFILE_DIR}" 2>/dev/null && for d in */; do printf '%s ' "${d%/}"; done)" >&2
            return 1
        fi
    done
    for name in "${names[@]}"; do
        name="${name// /}"
        [[ -z "${name}" ]] && continue
        dir="${CK_PROFILE_DIR}/${name}"
        # Profiles share CK_PROFILE_DIR/apply.sh; a profile can ship its own
        # apply.sh to override the shared driver.
        apply="${CK_PROFILE_DIR}/apply.sh"
        [[ -f "${dir}/apply.sh" ]] && apply="${dir}/apply.sh"
        # CMS gate: profile.json declares the CMS family it needs (e.g.
        # "drupal10"); match it as a prefix of the civibuild site type
        # (drupal10-demo, ...).
        want_cms="$(jq -r '.cms // empty' "${dir}/profile.json" 2>/dev/null || true)"
        if [[ -n "${want_cms}" && "${CIVICRM_SITE_TYPE:-}" != "${want_cms}"* ]]; then
            echo "[civikitchen] ERROR: profile '${name}' requires a ${want_cms} site; this site is '${CIVICRM_SITE_TYPE:-unknown}'." >&2
            return 1
        fi
        echo "[civikitchen] Applying profile '${name}' (needs network; this can take several minutes)..."
        # Pass CK_CREDENTIALS_FILE through explicitly (like ck_smtp/ck_demo_user
        # above) so a profile's configure-api-users.php + seeds resolve it
        # deterministically rather than relying on runuser's preserve-env. Empty
        # when unset -> the script's `?:` falls back to $HOME/api-credentials.txt
        # (no change for profiles that don't set it).
        ck_as_web env CK_CREDENTIALS_FILE="${CK_CREDENTIALS_FILE:-}" bash "${apply}" "${dir}"
    done
}

# First-boot provisioning hooks mounted into CK_INIT_D, run in lexical order:
# *.sh via bash (as root, for system setup), *.php via `cv scr` (as the web
# user, for Civi settings / seed data). A failing hook aborts the boot.
ck_run_init_hooks() {
    [[ -d "${CK_INIT_D}" ]] || return 0
    local hook
    for hook in "${CK_INIT_D}"/*; do
        [[ -e "${hook}" ]] || continue
        case "${hook}" in
            *.sh)
                echo "[civikitchen] init hook (bash): ${hook}"
                bash "${hook}"
                ;;
            *.php)
                echo "[civikitchen] init hook (cv scr): ${hook}"
                ck_as_web cv scr "${hook}"
                ;;
            *)
                echo "[civikitchen] init hook skipped (expects *.sh or *.php): ${hook}" >&2
                ;;
        esac
    done
}

# Heal root-owned files in the CiviCRM data dirs that root-run steps may have
# left behind — the web workers can't write them otherwise. Cheap no-op when
# ownership is already correct. -h: change symlinks, never their targets.
# -xdev: an extension bind-mounted below a data dir (the buildkit flavors put
# the CMS ext dir inside the site tree) belongs to the host user, and chowning
# it to the web user locks that user — a CI runner — out of its own checkout.
ck_heal_perms() {
    # shellcheck disable=SC2086 # CK_DATA_DIRS is a space-separated path list.
    find ${CK_DATA_DIRS} -xdev ! -user "${CK_WEB_USER}" \
        -exec chown -h "${CK_WEB_USER}:${CK_WEB_GROUP}" {} + 2>/dev/null || true
}

# Marker-gated STANDALONE post-install CONFIG bundle, run once: the steps that
# must follow `cv core:install` — dev settings, SMTP backend, isolated test DB,
# the Standalone auth provider, and an optional demo user. The marker is written
# only on success, so a step that hard-fails (the standaloneusers enable or the
# demo-user creation) exits the boot loudly AND re-runs the WHOLE — idempotent —
# sequence on the next start, instead of being silently skipped because the
# settings file already exists.
#
# ck_setup_test_db is ordered BEFORE the auth/demo-user steps deliberately: those
# can hard-fail (return 1 under the entrypoint's set -e), and test-DB isolation
# must be established regardless. If TEST_DB_DSN were left unwritten, a later
# headless phpunit run would fall back to — and WIPE — the dev DB. Putting it
# first means a demo-user failure can never strand test-DB isolation, even if it
# fails on every boot.
#
# Standalone-only — buildkit gets its demo user + isolated test DB from civibuild
# and calls ck_smtp directly, so it must NOT call this bundle (its entrypoint
# doesn't).
ck_post_install_config() {
    if [[ -f "${CK_CONFIGURED_MARKER}" ]]; then
        echo "[civikitchen] Already configured (${CK_CONFIGURED_MARKER}) — first-boot config knobs (SMTP, test DB, demo user) are not re-applied; remove the marker to re-run."
        return 0
    fi
    ck_dev_settings
    ck_smtp
    ck_setup_test_db
    ck_standalone_auth
    ck_demo_user
    touch "${CK_CONFIGURED_MARKER}"
}

# Marker-gated post-install provisioning bundle: core locales, profile,
# registry + mounted extensions, and init.d hooks, run once. The marker is written only on success
# so a failed step re-runs on the next start instead of being silently skipped.
# The profile goes first: it sets up the base stack that the user's extension
# knobs and init hooks layer on top of.
ck_post_install_provision() {
    if [[ -f "${CK_PROVISIONED_MARKER}" ]]; then
        echo "[civikitchen] Already provisioned (${CK_PROVISIONED_MARKER}) — CIVIKITCHEN_PROFILE / *_EXTENSIONS / init.d changes are not re-applied; remove the marker to re-run."
        return 0
    fi
    ck_locales
    ck_apply_profile
    ck_extra_extensions
    ck_enable_extensions
    ck_run_init_hooks
    touch "${CK_PROVISIONED_MARKER}"
}
