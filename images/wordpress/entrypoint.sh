#!/bin/bash
set -e

export PATH="/home/buildkit/buildkit/bin:${PATH}"

MYSQL_HOST="${MYSQL_HOST:-db}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-root}"
CIVICRM_SITE_TYPE="${CIVICRM_SITE_TYPE:-wp-demo}"
CIVICRM_VERSION="${CIVICRM_VERSION:-6.7.1}"
HTTPD_DOMAIN="${HTTPD_DOMAIN:-localhost}"
HTTPD_PORT="${HTTPD_PORT:-80}"

SITE_URL="http://${HTTPD_DOMAIN}:${HTTPD_PORT}"
SITE_DIR="/home/buildkit/buildkit/build/site"
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

# Build site on first run only
if [[ ! -f "${MARKER_FILE}" ]]; then
    echo "First run: building ${CIVICRM_SITE_TYPE} site..."

    # Configure amp for MySQL connection
    sudo -u buildkit bash -c "
        export PATH='/home/buildkit/buildkit/bin:\${PATH}'
        amp config:set \
            --mysql_type=mycnf \
            --httpd_type=none \
            --perm_type=none
        echo '[client]' > /home/buildkit/.my.cnf
        echo 'host=${MYSQL_HOST}' >> /home/buildkit/.my.cnf
        echo 'port=${MYSQL_PORT}' >> /home/buildkit/.my.cnf
        echo 'user=root' >> /home/buildkit/.my.cnf
        echo 'password=${MYSQL_ROOT_PASSWORD}' >> /home/buildkit/.my.cnf
    "

    # Create the CiviCRM site
    sudo -u buildkit bash -c "
        export PATH='/home/buildkit/buildkit/bin:\${PATH}'
        civibuild create site \
            --type '${CIVICRM_SITE_TYPE}' \
            --url '${SITE_URL}' \
            --civi-ver '${CIVICRM_VERSION}' \
            --admin-pass 'admin'
    "

    touch "${MARKER_FILE}"
    echo "Site created successfully."
else
    echo "Site already installed (skipping build)."
fi

# Start Apache
echo "Starting Apache..."
echo "Access: ${SITE_URL}"
echo "Login: admin / admin"
sudo apachectl -D FOREGROUND
