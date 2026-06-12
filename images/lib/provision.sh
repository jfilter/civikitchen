#!/bin/bash
# civikitchen shared provisioning library.
#
# Sourced by both entrypoints AFTER their CiviCRM site exists (standalone:
# `cv core:install`; buildkit: `civibuild create site`). It holds the
# CMS-agnostic first-boot provisioning so the standalone and buildkit images
# behave the same: auto-composer for bind-mounted extensions, dev settings,
# SMTP backend, an isolated test DB, registry + mounted extension enabling,
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
: "${CK_INIT_D:=/civikitchen-init.d}"
# ~/.cv.json site key under which TEST_DB_DSN is stored. Standalone keys by its
# bootstrap file; other CMSes key the site differently (resolved per image).
: "${CK_TEST_DB_CV_KEY:=/var/www/html/civicrm.standalone.php}"
# Where named profiles (images/profiles/<name>/) ship inside the image.
: "${CK_PROFILE_DIR:=/usr/local/share/civikitchen/profiles}"
# Optional civibuild-style settings.d dir (loaded into civicrm.settings.php).
# Only the buildkit entrypoint sets this; see ck_smtp.
: "${CK_SETTINGS_D:=}"

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
        if ( cd "${ext_dir}" && composer install --no-interaction --no-progress --prefer-dist ); then
            # Bind mounts belong to the host user; match vendor/ to the mount
            # owner so it isn't left root-owned on Linux hosts.
            chown -R --reference="${ext_dir}" "${ext_dir}/vendor" 2>/dev/null || true
        else
            echo "[civikitchen] WARN: composer install failed in ext/${ext_name} — set CIVIKITCHEN_AUTO_COMPOSER=0 or fix composer.json" >&2
        fi
    done
    shopt -u nullglob
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
    [[ -n "${CK_SETTINGS_D}" ]] && rm -f "${CK_SETTINGS_D}/100-mail.php"
}

# Demo login user. Opt-in via CIVIKITCHEN_DEMO_USER.
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
    mysql -h "${CIVICRM_DB_HOST}" -P "${CIVICRM_DB_PORT}" -u "${CIVICRM_DB_USER}" -p"${CIVICRM_DB_PASSWORD}" \
        -e "CREATE DATABASE IF NOT EXISTS \`${test_db_name}\`" 2>/dev/null \
        || echo "[civikitchen] WARN: could not pre-create ${test_db_name}; the test framework will create it on demand" >&2
    # cv merges ~/.cv.json into $GLOBALS['_CV'], keyed by the site bootstrap
    # path; civicrm.settings.php reads _CV['TEST_DB_DSN'] under
    # CIVICRM_UF=UnitTests. Write it for root (docker exec default) and the web
    # user. Don't clobber an existing ~/.cv.json (a project may have set its own).
    local cv_json
    cv_json=$(printf '{\n  "sites": {\n    "%s": {\n      "TEST_DB_DSN": "%s"\n    }\n  }\n}' "${CK_TEST_DB_CV_KEY}" "${test_db_dsn}")
    [[ -f /root/.cv.json ]] || printf '%s\n' "${cv_json}" > /root/.cv.json
    [[ -f "${CK_WEB_USER_HOME}/.cv.json" ]] || ck_as_web bash -c "printf '%s\n' '${cv_json}' > ${CK_WEB_USER_HOME}/.cv.json"
}

# Download + enable a comma-separated list of registry extensions.
# Each entry is a bare key (de.systopia.xcm) or key@URL for a pinned/forked
# release. Split download + enable into two cv calls (the combined form bombs
# for some extensions) and so later entries see earlier ones' deps installed.
ck_extra_extensions() {
    [[ -n "${CIVIKITCHEN_EXTRA_EXTENSIONS}" ]] || return 0
    echo "[civikitchen] Installing extra extensions: ${CIVIKITCHEN_EXTRA_EXTENSIONS}"
    local ext_spec ext_key
    local -a specs
    IFS=',' read -ra specs <<< "${CIVIKITCHEN_EXTRA_EXTENSIONS}"
    for ext_spec in "${specs[@]}"; do
        ext_spec="${ext_spec// /}"
        [[ -z "${ext_spec}" ]] && continue
        ext_key="${ext_spec%%@*}"
        echo "[civikitchen]   - ${ext_key}"
        ck_as_web cv ext:download -n --no-install "${ext_spec}"
        ck_as_web cv ext:enable "${ext_key}"
    done
}

# Enable extensions already present (e.g. bind-mounted into the ext dir) by key.
ck_enable_extensions() {
    [[ -n "${CIVIKITCHEN_ENABLE_EXTENSIONS}" ]] || return 0
    echo "[civikitchen] Enabling extensions: ${CIVIKITCHEN_ENABLE_EXTENSIONS}"
    local ext_key
    local -a keys
    IFS=',' read -ra keys <<< "${CIVIKITCHEN_ENABLE_EXTENSIONS}"
    for ext_key in "${keys[@]}"; do
        ext_key="${ext_key// /}"
        [[ -z "${ext_key}" ]] && continue
        ck_as_web cv ext:enable "${ext_key}"
    done
}

# Apply a named profile (extensions + seed data + API users) at first boot.
# Opt-in via CIVIKITCHEN_PROFILE=<name>; profiles ship in CK_PROFILE_DIR (see
# images/profiles/). Needs network (git clones) and can take several minutes.
# Runs inside ck_post_install_provision, so it is marker-gated: it applies
# once, and a failure aborts the boot (no marker) and re-runs on next start.
ck_apply_profile() {
    [[ -n "${CIVIKITCHEN_PROFILE:-}" ]] || return 0
    local dir="${CK_PROFILE_DIR}/${CIVIKITCHEN_PROFILE}"
    if [[ ! -f "${dir}/profile.json" ]]; then
        echo "[civikitchen] ERROR: unknown profile '${CIVIKITCHEN_PROFILE}'." >&2
        echo "[civikitchen] Available profiles: $(cd "${CK_PROFILE_DIR}" 2>/dev/null && for d in */; do printf '%s ' "${d%/}"; done)" >&2
        return 1
    fi
    # Profiles share CK_PROFILE_DIR/apply.sh; a profile can ship its own
    # apply.sh to override the shared driver.
    local apply="${CK_PROFILE_DIR}/apply.sh"
    [[ -f "${dir}/apply.sh" ]] && apply="${dir}/apply.sh"
    # CMS gate: profile.json declares the CMS family it needs (e.g. "drupal10");
    # match it as a prefix of the civibuild site type (drupal10-demo, ...).
    local want_cms
    want_cms="$(jq -r '.cms // empty' "${dir}/profile.json" 2>/dev/null || true)"
    if [[ -n "${want_cms}" && "${CIVICRM_SITE_TYPE:-}" != "${want_cms}"* ]]; then
        echo "[civikitchen] ERROR: profile '${CIVIKITCHEN_PROFILE}' requires a ${want_cms} site; this site is '${CIVICRM_SITE_TYPE:-unknown}'." >&2
        return 1
    fi
    echo "[civikitchen] Applying profile '${CIVIKITCHEN_PROFILE}' (needs network; this can take several minutes)..."
    ck_as_web bash "${apply}" "${dir}"
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
ck_heal_perms() {
    # shellcheck disable=SC2086 # CK_DATA_DIRS is a space-separated path list.
    find ${CK_DATA_DIRS} ! -user "${CK_WEB_USER}" \
        -exec chown -h "${CK_WEB_USER}:${CK_WEB_GROUP}" {} + 2>/dev/null || true
}

# Marker-gated post-install provisioning bundle: profile, registry + mounted
# extensions, and init.d hooks, run once. The marker is written only on success
# so a failed step re-runs on the next start instead of being silently skipped.
# The profile goes first: it sets up the base stack that the user's extension
# knobs and init hooks layer on top of.
ck_post_install_provision() {
    [[ -f "${CK_PROVISIONED_MARKER}" ]] && return 0
    ck_apply_profile
    ck_extra_extensions
    ck_enable_extensions
    ck_run_init_hooks
    touch "${CK_PROVISIONED_MARKER}"
}
