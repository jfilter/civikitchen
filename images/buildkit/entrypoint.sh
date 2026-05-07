#!/bin/bash
set -e

export PATH="/home/buildkit/buildkit/bin:${PATH}"

# Xdebug toggle (shared with standalone image).
. /usr/local/share/civikitchen/xdebug-toggle.sh

MYSQL_HOST="${MYSQL_HOST:-db}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-root}"
# Default site type comes from the build arg DEFAULT_SITE_TYPE — :drupal10
# tags ship drupal10-demo, :wordpress tags ship wp-demo. Users can override
# at runtime by setting CIVICRM_SITE_TYPE.
CIVICRM_SITE_TYPE="${CIVICRM_SITE_TYPE:-${CIVICRM_SITE_TYPE_DEFAULT:-drupal10-demo}}"
CIVICRM_VERSION="${CIVICRM_VERSION:-6.12.1}"

# SITE_URL is the URL the browser uses to reach this container.
# Must match the external port from your Docker port mapping (-p flag).
# Examples:
#   docker run -p 8080:80  →  SITE_URL=http://localhost:8080
#   docker run -p 80:80    →  SITE_URL=http://localhost (default)
if [[ -z "${SITE_URL}" ]]; then
    HTTPD_DOMAIN="${HTTPD_DOMAIN:-localhost}"
    HTTPD_PORT="${HTTPD_PORT:-80}"
    if [[ "${HTTPD_PORT}" == "80" ]]; then
        SITE_URL="http://${HTTPD_DOMAIN}"
    else
        SITE_URL="http://${HTTPD_DOMAIN}:${HTTPD_PORT}"
    fi
fi

MARKER_FILE="/home/buildkit/.site-installed"

echo "CiviCRM Dev Image (${CIVICRM_SITE_TYPE})"
echo "=========================================="
echo "Site URL: ${SITE_URL}"

# Wait for MySQL via PHP mysqli — matches the standalone entrypoint and
# sidesteps Debian's default-mysql-client, which now enforces TLS by default
# and refuses to talk to a plain MariaDB sidecar (closes the connection
# before auth, so the server logs "unauthenticated" aborts).
echo "Waiting for MySQL at ${MYSQL_HOST}:${MYSQL_PORT}..."
attempt=0
until php -r '
    $m = @new mysqli(
        getenv("MYSQL_HOST"),
        "root",
        getenv("MYSQL_ROOT_PASSWORD"),
        "",
        (int) getenv("MYSQL_PORT")
    );
    exit($m->connect_errno ? 1 : 0);
' 2>/dev/null; do
    attempt=$((attempt + 1))
    if [[ "${attempt}" -ge 60 ]]; then
        echo "ERROR: MySQL not ready after 120s" >&2
        exit 1
    fi
    sleep 2
done
echo "MySQL is ready."

# Build site on first run only (as buildkit user)
if [[ ! -f "${MARKER_FILE}" ]]; then
    echo "First run: building ${CIVICRM_SITE_TYPE} site..."

    BK="su -s /bin/bash buildkit -c"
    export PATH="/home/buildkit/buildkit/bin:${PATH}"

    # Configure amp for MySQL connection
    ${BK} "export PATH='${PATH}' && amp config:set \
        --mysql_type=mycnf \
        --httpd_type=none \
        --perm_type=none"

    # ssl-mode=DISABLED: Debian Bookworm's mariadb-client defaults to
    # PREFERRED, which refuses a plain (non-TLS) sidecar by closing the
    # connection before auth — exactly the trap the buildkit user wants to
    # avoid in dev. Force plain transport.
    cat > /home/buildkit/.my.cnf <<MYCNF
[client]
host=${MYSQL_HOST}
port=${MYSQL_PORT}
user=root
password=${MYSQL_ROOT_PASSWORD}
ssl-mode=DISABLED
MYCNF
    chown buildkit:buildkit /home/buildkit/.my.cnf

    # Create the CiviCRM site
    ${BK} "export PATH='${PATH}' && civibuild create site \
        --type '${CIVICRM_SITE_TYPE}' \
        --url '${SITE_URL}' \
        --civi-ver '${CIVICRM_VERSION}' \
        --admin-pass 'admin'"

    # Workaround: brick/money 0.12+ renamed ISOCurrencyProvider → IsoCurrencyProvider
    # but CiviCRM still references the old name. Pin to compatible version.
    SITE_DIR="/home/buildkit/buildkit/build/site"
    if [[ -f "${SITE_DIR}/composer.json" ]]; then
        ${BK} "export PATH='${PATH}' && cd '${SITE_DIR}' && composer require 'brick/money:<0.12' -W --no-interaction 2>/dev/null" || true
    fi

    touch "${MARKER_FILE}"
    echo "Site created successfully."
else
    echo "Site already installed (skipping build)."
fi

# Start Apache (needs root for port 80)
echo "Starting Apache..."
echo "Access: ${SITE_URL}"
echo "Login: admin / admin"
apachectl -D FOREGROUND
