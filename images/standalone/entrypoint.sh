#!/bin/bash
set -e

# civikitchen standalone entrypoint.
#
# 1. If XDEBUG_MODE is set, activate xdebug for this container.
# 2. If CIVICRM_AUTO_INSTALL=1 and CiviCRM is not yet installed, wait for the
#    database and run `cv core:install`.
# 3. Run the shared first-boot provisioning (images/lib/provision.sh).
# 4. Hand off to the upstream civicrm-docker-entrypoint.
#
# Why runtime install (not a build-time bake like the buildkit demo images)?
# The demo images bake an embedded MariaDB into the same container as CiviCRM,
# so the baked DB sits on localhost at both build and run time. This image
# instead points at an external MariaDB whose host/credentials are only known
# at runtime, so baking the DB would require regenerating civicrm.settings.php
# on first start anyway. The saving wasn't worth the extra build complexity.

# ---------------------------------------------------------------------------
# Xdebug toggle (shared with buildkit image).
. /usr/local/share/civikitchen/xdebug-toggle.sh

# ---------------------------------------------------------------------------
# Env-var convention: CIVIKITCHEN_* are this image's own behavior knobs.
# CIVICRM_* is reserved for the upstream image contract (CIVICRM_AUTO_INSTALL,
# CIVICRM_DB_*), for describing the CiviCRM target (CIVICRM_VERSION,
# CIVICRM_SITE_TYPE) and for CiviCRM's own variables (CIVICRM_UF, ...).
# Legacy CIVICRM_-spelled kitchen vars keep working with a deprecation warning.
_ck_legacy() {
    local new="$1" legacy="$2"
    if [[ -z "${!new+x}" && -n "${!legacy+x}" ]]; then
        echo "[civikitchen] WARN: ${legacy} is deprecated - use ${new}" >&2
        export "${new}=${!legacy}"
    fi
}
_ck_legacy CIVIKITCHEN_COMPONENTS        CIVICRM_COMPONENTS
_ck_legacy CIVIKITCHEN_DEMO_USER         CIVICRM_DEMO_USER
_ck_legacy CIVIKITCHEN_DEMO_PASS         CIVICRM_DEMO_PASS
_ck_legacy CIVIKITCHEN_DEMO_EMAIL        CIVICRM_DEMO_EMAIL
_ck_legacy CIVIKITCHEN_SMTP_HOST         CIVICRM_SMTP_HOST
_ck_legacy CIVIKITCHEN_SMTP_PORT         CIVICRM_SMTP_PORT
_ck_legacy CIVIKITCHEN_EXTRA_EXTENSIONS  CIVICRM_EXTRA_EXTENSIONS
_ck_legacy CIVIKITCHEN_ENABLE_EXTENSIONS CIVICRM_ENABLE_EXTENSIONS
_ck_legacy CIVIKITCHEN_AUTO_COMPOSER     CIVICRM_AUTO_COMPOSER
_ck_legacy CIVIKITCHEN_SITE_URL          SITE_URL

# ---------------------------------------------------------------------------
# Shared provisioning library. ck_as_web runs a command as the web user
# (www-data) against the standalone CiviCRM site. The CK_* parameters default
# to the standalone layout, so nothing else needs overriding here.
ck_as_web() { runuser -u www-data -- "$@"; }
. /usr/local/share/civikitchen/provision.sh

# ---------------------------------------------------------------------------
# Extra OS packages (e.g. libreoffice-writer + unoconv for CiviOffice
# rendering) without baking them into the image. Comma- or space-separated.
# Installed once per container; restarts skip already-present packages.
if [[ -n "${CIVIKITCHEN_EXTRA_PACKAGES}" ]]; then
    _ck_missing=()
    for pkg in ${CIVIKITCHEN_EXTRA_PACKAGES//,/ }; do
        dpkg -s "${pkg}" >/dev/null 2>&1 || _ck_missing+=("${pkg}")
    done
    if [[ ${#_ck_missing[@]} -gt 0 ]]; then
        echo "[civikitchen] Installing extra packages: ${_ck_missing[*]}"
        apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${_ck_missing[@]}"
        rm -rf /var/lib/apt/lists/*
    fi
fi

# ---------------------------------------------------------------------------
# Auto-run composer install for bind-mounted extensions. Runs before the
# auto-install so extensions enabled during install (e.g. via
# CIVIKITCHEN_ENABLE_EXTENSIONS) already have their vendor/ in place.
ck_auto_composer

# ---------------------------------------------------------------------------
# Auto-install.
export CIVICRM_DB_HOST="${CIVICRM_DB_HOST:-db}"
export CIVICRM_DB_PORT="${CIVICRM_DB_PORT:-3306}"
export CIVICRM_DB_NAME="${CIVICRM_DB_NAME:-civicrm}"
export CIVICRM_DB_USER="${CIVICRM_DB_USER:-civicrm}"
export CIVICRM_DB_PASSWORD="${CIVICRM_DB_PASSWORD:-civicrm}"

CIVICRM_AUTO_INSTALL="${CIVICRM_AUTO_INSTALL:-0}"
SETTINGS_FILE="/var/www/html/private/civicrm.settings.php"

if [[ "${CIVICRM_AUTO_INSTALL}" == "1" && ! -f "${SETTINGS_FILE}" ]]; then
    echo "[civikitchen] CIVICRM_AUTO_INSTALL=1, settings not present"
    echo "[civikitchen] Waiting for database at ${CIVICRM_DB_HOST}:${CIVICRM_DB_PORT}..."

    attempt=0
    until php -r '
        // mysqli_report() must be OFF or PHP 8.1+ throws on every failed
        // connect attempt during the wait loop, which is just noise here.
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = @new mysqli(
            getenv("CIVICRM_DB_HOST"),
            getenv("CIVICRM_DB_USER"),
            getenv("CIVICRM_DB_PASSWORD"),
            getenv("CIVICRM_DB_NAME"),
            (int) getenv("CIVICRM_DB_PORT")
        );
        exit($m->connect_errno ? 1 : 0);
    ' 2>/dev/null; do
        attempt=$((attempt + 1))
        if [[ "${attempt}" -ge 30 ]]; then
            echo "[civikitchen] ERROR: database not reachable after 60s" >&2
            exit 1
        fi
        sleep 2
    done

    DB_URL="mysql://${CIVICRM_DB_USER}:${CIVICRM_DB_PASSWORD}@${CIVICRM_DB_HOST}:${CIVICRM_DB_PORT}/${CIVICRM_DB_NAME}"
    # CIVIKITCHEN_SITE_URL is the URL the browser uses. It MUST match the host:port the
    # user opens — Civi bakes it into every JS/CSS asset URL, so a mismatch
    # silently breaks the Angular login form because asset fetches fail.
    CIVIKITCHEN_SITE_URL="${CIVIKITCHEN_SITE_URL:-http://localhost}"

    # cv core:install --comp accepts a comma-separated list. cv's own default
    # enables only the core component, which is wrong for a dev image —
    # extensions assuming CiviContribute/CiviCase/etc. would silently fail.
    # Default to all standard components; user can override (or pass an
    # empty string to fall back to cv's core-only default).
    CIVIKITCHEN_COMPONENTS="${CIVIKITCHEN_COMPONENTS-CiviEvent,CiviContribute,CiviMember,CiviMail,CiviPledge,CiviCase,CiviReport,CiviCampaign}"
    INSTALL_OPTS=()
    if [[ -n "${CIVIKITCHEN_COMPONENTS}" ]]; then
        INSTALL_OPTS+=(--comp="${CIVIKITCHEN_COMPONENTS}")
    fi

    echo "[civikitchen] Running cv core:install (cmsBaseUrl=${CIVIKITCHEN_SITE_URL}${CIVIKITCHEN_COMPONENTS:+, components=${CIVIKITCHEN_COMPONENTS}})..."
    # cv --url is the documented flag for setting cmsBaseUrl during install.
    # It populates the model BEFORE init plugins run, so every
    # $civicrm_paths[*]['url'] is derived from CIVIKITCHEN_SITE_URL (cms.root,
    # civicrm.root, civicrm.files, civicrm.vendor — all of them).
    #
    # -K keeps existing tables — survives `docker compose down` (without -v)
    # where settings file is lost but DB volume persists.
    # Run as www-data: /var/www/html/private/ is owned by www-data, and the
    # install creates settings files + cache dirs there. Running as root
    # leaves them root-owned and apache can't later write to the cache dir.
    runuser -u www-data -- cv core:install -n -K --url="${CIVIKITCHEN_SITE_URL}" --db="${DB_URL}" "${INSTALL_OPTS[@]}"
    echo "[civikitchen] CiviCRM installed."

    # Shared post-install configuration (dev settings, SMTP backend, demo user,
    # isolated test DB) — see images/lib/provision.sh.
    ck_dev_settings
    ck_smtp
    ck_demo_user
    ck_setup_test_db
fi

# ---------------------------------------------------------------------------
# Post-install provisioning: extra/mounted extensions and first-boot hooks.
# ck_post_install_provision is marker-gated (writes the marker last) so a
# failed hook exits loudly AND retries on the next start, instead of being
# silently skipped because the settings file already exists.
if [[ "${CIVICRM_AUTO_INSTALL}" == "1" && -f "${SETTINGS_FILE}" ]]; then
    ck_post_install_provision
fi

# ---------------------------------------------------------------------------
# Heal root-owned files in the CiviCRM data dirs so the www-data web workers
# can write caches/locks/uploads. Cheap no-op when ownership is already correct.
ck_heal_perms

exec civicrm-docker-entrypoint "$@"
