#!/bin/bash
set -e

# civikitchen standalone entrypoint.
#
# 1. If XDEBUG_MODE is set, activate xdebug for this container.
# 2. If CIVICRM_AUTO_INSTALL=1 and CiviCRM is not yet installed, wait for the
#    database and run `cv core:install`.
# 3. Hand off to the upstream civicrm-docker-entrypoint.
#
# Why runtime install (not a build-time SQL dump like allinone/)?
# allinone's embedded MariaDB lives in the same container as CiviCRM,
# so a build-time install + mysqldump roundtrip targets a localhost DB
# that's still localhost at runtime. This image is meant to point at an
# external MariaDB whose host/credentials are only known at runtime, so
# baking a dump would require regenerating civicrm.settings.php on first
# start anyway. The ~8s saving wasn't worth the extra build complexity.

# ---------------------------------------------------------------------------
# Xdebug toggle.
# pcov is always enabled (cheap, coverage-only). Xdebug is opt-in because it
# slows down every request. Set XDEBUG_MODE to debug, develop, or any combo
# from https://xdebug.org/docs/all_settings#mode to turn it on. Leave unset
# (or set to "off") to skip.
XDEBUG_INI="/usr/local/etc/php/conf.d/xdebug.ini"
if [[ -n "${XDEBUG_MODE}" && "${XDEBUG_MODE}" != "off" ]]; then
    cat > "${XDEBUG_INI}" <<EOF
zend_extension=xdebug.so
xdebug.mode=${XDEBUG_MODE}
xdebug.client_host=${XDEBUG_CLIENT_HOST:-host.docker.internal}
xdebug.client_port=${XDEBUG_CLIENT_PORT:-9003}
xdebug.start_with_request=${XDEBUG_START_WITH_REQUEST:-trigger}
xdebug.discover_client_host=${XDEBUG_DISCOVER_CLIENT_HOST:-0}
xdebug.idekey=${XDEBUG_IDEKEY:-VSCODE}
EOF
    echo "[civikitchen] xdebug enabled (mode=${XDEBUG_MODE}, client=${XDEBUG_CLIENT_HOST:-host.docker.internal}:${XDEBUG_CLIENT_PORT:-9003})"
elif [[ -f "${XDEBUG_INI}" ]]; then
    rm -f "${XDEBUG_INI}"
fi

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
    # SITE_URL is the URL the browser uses. It MUST match the host:port the
    # user opens — Civi bakes it into every JS/CSS asset URL, so a mismatch
    # silently breaks the Angular login form because asset fetches fail.
    SITE_URL="${SITE_URL:-http://localhost}"

    echo "[civikitchen] Running cv core:install (cmsBaseUrl=${SITE_URL})..."
    # cv --url is the documented flag for setting cmsBaseUrl during install.
    # It populates the model BEFORE init plugins run, so every
    # $civicrm_paths[*]['url'] is derived from SITE_URL (cms.root,
    # civicrm.root, civicrm.files, civicrm.vendor — all of them).
    #
    # -K keeps existing tables — survives `docker compose down` (without -v)
    # where settings file is lost but DB volume persists.
    # Run as www-data: /var/www/html/private/ is owned by www-data, and the
    # install creates settings files + cache dirs there. Running as root
    # leaves them root-owned and apache can't later write to the cache dir.
    runuser -u www-data -- cv core:install -n -K --url="${SITE_URL}" --db="${DB_URL}"
    echo "[civikitchen] CiviCRM installed."
fi

exec civicrm-docker-entrypoint "$@"
