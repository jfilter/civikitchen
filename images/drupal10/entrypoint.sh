#!/bin/bash
set -e

export PATH="/home/buildkit/buildkit/bin:${PATH}"

MYSQL_HOST="${MYSQL_HOST:-db}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-root}"
CIVICRM_SITE_TYPE="${CIVICRM_SITE_TYPE:-drupal10-demo}"
CIVICRM_VERSION="${CIVICRM_VERSION:-6.7.1}"
HTTPD_DOMAIN="${HTTPD_DOMAIN:-localhost}"
HTTPD_PORT="${HTTPD_PORT:-80}"

SITE_URL="http://${HTTPD_DOMAIN}:${HTTPD_PORT}"
MARKER_FILE="/home/buildkit/.site-installed"

echo "CiviCRM Dev Image (${CIVICRM_SITE_TYPE})"
echo "=========================================="

# Wait for MySQL
echo "Waiting for MySQL at ${MYSQL_HOST}:${MYSQL_PORT}..."
attempt=0
max_attempts=60
until mysql -h "${MYSQL_HOST}" -P "${MYSQL_PORT}" -u root -p"${MYSQL_ROOT_PASSWORD}" -e "SELECT 1" > /dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [[ "${attempt}" -ge "${max_attempts}" ]]; then
        echo "ERROR: MySQL not ready after ${max_attempts} attempts"
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

    cat > /home/buildkit/.my.cnf <<MYCNF
[client]
host=${MYSQL_HOST}
port=${MYSQL_PORT}
user=root
password=${MYSQL_ROOT_PASSWORD}
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
