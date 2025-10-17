#!/bin/bash
set -e

BUILDKIT_DIR="/home/buildkit/buildkit"

echo "==================================="
echo "CiviCRM Buildkit Docker Container"
echo "==================================="

# Check if buildkit is already installed (check for bin directory)
if [ ! -d "$BUILDKIT_DIR/bin" ]; then
    echo "Installing buildkit for the first time..."

    # Remove empty buildkit directory if it exists
    if [ -d "$BUILDKIT_DIR" ]; then
        sudo rm -rf "$BUILDKIT_DIR"
    fi

    # Clone buildkit repository
    git clone https://github.com/civicrm/civicrm-buildkit.git "$BUILDKIT_DIR"
    cd "$BUILDKIT_DIR"

    # Download buildkit tools
    echo "Downloading buildkit tools..."
    ./bin/civi-download-tools

    # Add buildkit to PATH
    echo 'export PATH="$HOME/buildkit/bin:$PATH"' >> /home/buildkit/.bashrc
    export PATH="$HOME/buildkit/bin:$PATH"

    # Configure amp (non-interactive)
    echo "Configuring amp..."
    mkdir -p /home/buildkit/.amp

    cat > /home/buildkit/.amp/config.yml <<EOF
hosts_type: file
hosts_file_path: /etc/hosts
perm_type: none
httpd_type: apache24
httpd_restart_command: 'sudo apachectl graceful'
httpd_shared_ports:
  - 80
httpd_vhost_dir: /etc/apache2/sites-enabled
httpd_visibility: all
db_type: mysql_dsn
mysql_dsn: mysql://root:${MYSQL_ROOT_PASSWORD}@${MYSQL_HOST}:${MYSQL_PORT}
mysql_type: mycnf
mysql_cnf_path: /home/buildkit/.my.cnf
php_type: detect
redis_type: none
hosts_ip: 127.0.0.1
EOF

    # Create MySQL config file
    cat > /home/buildkit/.my.cnf <<EOF
[client]
host=${MYSQL_HOST}
port=${MYSQL_PORT}
user=root
password=${MYSQL_ROOT_PASSWORD}
EOF

    chmod 600 /home/buildkit/.my.cnf

    # Test amp
    echo "Testing amp configuration..."
    amp test || true

    echo "Buildkit installation complete!"
else
    echo "Buildkit already installed."
    export PATH="$HOME/buildkit/bin:$PATH"
fi

# Ensure amp MySQL connection is properly configured
echo "Verifying amp MySQL configuration..."
if ! amp config:get --out=table | grep -q "mysql_dsn.*mysql://root"; then
    echo "Updating amp MySQL DSN..."
    amp config:set --mysql_dsn="mysql://root:${MYSQL_ROOT_PASSWORD}@${MYSQL_HOST}:${MYSQL_PORT}"
fi

# Wait for MySQL to be ready
echo "===================================="
echo "Waiting for MySQL to be ready..."
echo "===================================="

max_attempts=30
attempt=0
while [ $attempt -lt $max_attempts ]; do
    if mysql -h"${MYSQL_HOST}" -P"${MYSQL_PORT}" -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "SELECT 1" >/dev/null 2>&1; then
        echo "✓ MySQL is ready and accepting connections!"
        break
    fi

    attempt=$((attempt + 1))
    echo "Waiting for MySQL... ($attempt/$max_attempts)"
    sleep 2
done

if [ $attempt -eq $max_attempts ]; then
    echo "✗ MySQL failed to become ready after $max_attempts attempts"
    exit 1
fi

# Auto-create CiviCRM site if requested
if [ -n "${CIVICRM_SITE_TYPE}" ] && [ "${CIVICRM_SITE_TYPE}" != "false" ]; then
    SITE_DIR="/home/buildkit/site"
    SITE_URL="http://${HTTPD_DOMAIN}"

    # Check if site already exists
    if [ ! -d "$SITE_DIR" ]; then
        echo "===================================="
        echo "Auto-creating CiviCRM site"
        echo "Site type: ${CIVICRM_SITE_TYPE}"
        echo "URL: $SITE_URL"
        if [ -n "${CIVICRM_VERSION}" ]; then
            echo "CiviCRM version: ${CIVICRM_VERSION}"
        fi
        echo "===================================="

        # Create the site with optional version specification
        if [ -n "${CIVICRM_VERSION}" ]; then
            civibuild create site --type "${CIVICRM_SITE_TYPE}" --url "$SITE_URL" --civi-ver "${CIVICRM_VERSION}" --force
        else
            civibuild create site --type "${CIVICRM_SITE_TYPE}" --url "$SITE_URL" --force
        fi

        echo "===================================="
        echo "Site creation complete!"
        echo "Access your site at: http://localhost:${HTTPD_PORT}"
        echo "===================================="
    else
        echo "Site already exists, skipping creation."
    fi
fi

# Configure static Apache vhost for the single site
echo "Configuring Apache for single-site setup..."

sudo tee /etc/apache2/sites-available/000-default.conf > /dev/null <<EOF
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    ServerName ${HTTPD_DOMAIN}
    DocumentRoot /home/buildkit/site/web

    <Directory /home/buildkit/site/web>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Fallback for when site doesn't exist yet
    <Directory /home/buildkit>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

echo "==================================="
echo "Starting Apache..."
echo "==================================="
echo ""
echo "Services available at:"
echo "  - CiviCRM site: http://localhost:${HTTPD_PORT}"
echo "  - PHPMyAdmin: http://localhost:8081"
echo "  - Maildev: http://localhost:1080"
echo ""
if [ -z "${CIVICRM_SITE_TYPE}" ] || [ "${CIVICRM_SITE_TYPE}" = "false" ]; then
    echo "No auto-site configured. To create a site manually:"
    echo "  docker-compose exec civicrm civibuild create site --type drupal10-demo --url http://${HTTPD_DOMAIN}"
    echo ""
fi
echo "To access the container shell:"
echo "  docker-compose exec civicrm bash"
echo ""

# Start Apache in foreground
exec sudo apachectl -D FOREGROUND
